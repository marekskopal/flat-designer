<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use finfo;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectFile;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\FloorUnderlayRepository;
use FlatDesigner\Model\Repository\ProjectFileRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Storage\FileStorageInterface;
use FlatDesigner\Service\Storage\S3Config;
use Throwable;
use const FILEINFO_MIME_TYPE;
use const PATHINFO_EXTENSION;

/**
 * Files of a project. The row is written first and removed again when storing the bytes fails, so a
 * row never points to a missing object; on delete the object goes after the row.
 */
final readonly class ProjectFileProvider
{
	private const int MaxFilenameLength = 200;

	/** Files whose content is read as text (`readText`). */
	private const array TextMimeTypes = ['text/plain', 'text/csv', 'application/json'];

	private const int MaxTextBytes = 200000;

	/** Images the viewer draws (an underlay, a picture): what browsers decode, and what the image tools read. */
	public const array ImageMimeTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

	public function __construct(
		private ProjectFileRepository $projectFileRepository,
		private FloorUnderlayRepository $underlayRepository,
		private ShownPictures $shownPictures,
		private FileStorageInterface $fileStorage,
		private S3Config $s3Config,
		private ProjectRevisionService $revisionService,
	) {
	}

	/** @return list<ProjectFile> */
	public function list(Project $project): array
	{
		return $this->projectFileRepository->findByProject($project->id);
	}

	public function find(Project $project, int $fileId): ?ProjectFile
	{
		return $this->projectFileRepository->findOneInProject($project->id, $fileId);
	}

	public function upload(?User $author, Project $project, string $filename, string $body): ProjectFile
	{
		$size = strlen($body);
		if ($size === 0) {
			throw new ProjectException('The file is empty.');
		}
		if ($size > $this->s3Config->maxFileSizeBytes) {
			throw new ProjectException(
				sprintf('The file is larger than %d MB.', intdiv($this->s3Config->maxFileSizeBytes, 1024 * 1024)),
				413,
			);
		}

		$filename = self::cleanFilename($filename);
		$mimeType = self::detectMimeType($body);

		$file = new ProjectFile(
			project: $project,
			filename: $filename,
			mimeType: $mimeType,
			size: $size,
			storageKey: sprintf('projects/%d/%s%s', $project->id, bin2hex(random_bytes(16)), self::extension($filename)),
			uploadedBy: $author,
		);
		$file->createdAt = new DateTimeImmutable();
		$file->updatedAt = $file->createdAt;
		$this->projectFileRepository->persist($file);

		try {
			$this->fileStorage->put($file->storageKey, $body, $mimeType);
		} catch (Throwable $e) {
			$this->projectFileRepository->delete($file);

			throw $e;
		}

		return $file;
	}

	public function readContent(ProjectFile $file): string
	{
		return $this->fileStorage->get($file->storageKey);
	}

	public function isText(ProjectFile $file): bool
	{
		return in_array($file->mimeType, self::TextMimeTypes, true);
	}

	/** A raster image the viewer and the image tools can draw (`ImageMimeTypes`; SVG is a document, never served as one). */
	public function isImage(ProjectFile $file): bool
	{
		return in_array($file->mimeType, self::ImageMimeTypes, true);
	}

	/** The content of a text file (plain, CSV, JSON) of a sensible size. */
	public function readText(ProjectFile $file): string
	{
		if (!$this->isText($file)) {
			throw new ProjectException(sprintf('File "%s" is %s, not a text file.', $file->filename, $file->mimeType));
		}
		if ($file->size > self::MaxTextBytes) {
			throw new ProjectException(
				sprintf('File "%s" is larger than %d kB of text.', $file->filename, intdiv(self::MaxTextBytes, 1000)),
			);
		}

		return $this->readContent($file);
	}

	/**
	 * The floor underlays laid from the file go with it (ON DELETE CASCADE), and items showing it as a picture keep its id
	 * but draw a plain one; either way open viewers are told to reload.
	 */
	public function delete(ProjectFile $file): void
	{
		$shown = $this->underlayRepository->findByFile($file->id) !== [] || $this->shownPictures->isShownIn($file->project, $file->id);
		$this->projectFileRepository->delete($file);
		$this->fileStorage->delete($file->storageKey);
		if ($shown) {
			$this->revisionService->bump($file->project);
		}
	}

	/** @return list<string> storage keys of all files of the project, to delete after the project rows */
	public function storageKeys(Project $project): array
	{
		return array_map(fn (ProjectFile $file): string => $file->storageKey, $this->list($project));
	}

	/** @param list<string> $storageKeys */
	public function deleteStoredObjects(array $storageKeys): void
	{
		foreach ($storageKeys as $storageKey) {
			$this->fileStorage->delete($storageKey);
		}
	}

	/** Base name without control characters, collapsed whitespace and at most 200 characters; Unicode stays. */
	public static function cleanFilename(string $filename): string
	{
		$name = basename(str_replace('\\', '/', $filename));
		$name = preg_replace('/\p{C}+/u', '', $name) ?? '';
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
		$name = trim($name, '.');
		if (mb_strlen($name) > self::MaxFilenameLength) {
			$extension = self::extension($name);
			$name = mb_substr($name, 0, self::MaxFilenameLength - strlen($extension)) . $extension;
		}

		return $name === '' ? 'file' : $name;
	}

	/** Lower-cased ASCII extension with the dot (".jpg"), or "" — used in storage keys. */
	private static function extension(string $filename): string
	{
		$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

		return preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? '.' . $extension : '';
	}

	/** From the content, never from what the client claims. */
	private static function detectMimeType(string $body): string
	{
		$mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($body);

		return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
	}
}
