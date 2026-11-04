<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

use DateTimeImmutable;
use Izbik\Model\Entity\FurnitureDefinition;
use Izbik\Model\Entity\ModelAsset;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\ProjectFile;
use Izbik\Model\Entity\ProjectMaterialPrice;
use Izbik\Model\Repository\FurnitureDefinitionRepository;
use Izbik\Model\Repository\ModelAssetRepository;
use Izbik\Model\Repository\ProjectFileRepository;
use Izbik\Model\Repository\ProjectMaterialPriceRepository;
use Izbik\Service\Project\ProjectSnapshotService;
use Izbik\Service\Storage\FileStorageInterface;
use MarekSkopal\ORM\ORM;
use RuntimeException;
use stdClass;
use Throwable;
use ZipArchive;
use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Exports a project's working copy as a `ProjectBundle`: `json()` without its files, `zip()` with them. What goes in is
 * read in one transaction, so the snapshot and the lists of files and models agree while others edit the project. The
 * zip is written one stored object at a time: memory holds the largest one, and the temporary directory a copy of each
 * until the archive is closed.
 */
final readonly class ProjectExporter
{
	/** Already compressed: stored as they are. */
	private const array StoredMimeTypes = [
		'image/png',
		'image/jpeg',
		'image/webp',
		'image/gif',
		'application/pdf',
		'application/zip',
		'model/gltf-binary',
	];

	public function __construct(
		private ProjectSnapshotService $snapshotService,
		private ProjectFileRepository $fileRepository,
		private ModelAssetRepository $modelRepository,
		private FurnitureDefinitionRepository $definitionRepository,
		private ProjectMaterialPriceRepository $priceRepository,
		private FileStorageInterface $storage,
		private ORM $orm,
	) {
	}

	/** The bundle's `project.json` alone: the project without its files and models. */
	public function json(Project $project): string
	{
		return $this->encode($this->read($project, false)['manifest']);
	}

	/**
	 * Writes the bundle with the project's files and models as a zip to `$path` (replaced if it exists). On a failure
	 * nothing is left at `$path`.
	 */
	public function zip(Project $project, string $path): void
	{
		['manifest' => $manifest, 'files' => $files, 'models' => $models] = $this->read($project, true);

		$directory = sys_get_temp_dir() . '/izbik-export-' . bin2hex(random_bytes(8));
		if (!mkdir($directory, 0700)) {
			throw new RuntimeException(sprintf('Cannot create the temporary directory %s.', $directory));
		}
		$zip = new ZipArchive();
		$open = false;

		try {
			if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
				throw new RuntimeException(sprintf('Cannot write %s.', $path));
			}
			$open = true;
			$zip->addFromString(ProjectBundle::Manifest, $this->encode($manifest));
			foreach ($files as $file) {
				$this->add($zip, $directory, ProjectBundle::filePath($file->id, $file->filename), $file->storageKey, $file->mimeType);
			}
			foreach ($models as $model) {
				$this->add($zip, $directory, ProjectBundle::modelPath($model->id), $model->storageKey, 'model/gltf-binary');
			}
			$open = false;
			if (!$zip->close()) {
				throw new RuntimeException(sprintf('Cannot write %s.', $path));
			}
		} catch (Throwable $e) {
			if ($open) {
				$zip->unchangeAll();
				$zip->close();
			}
			if (is_file($path)) {
				unlink($path);
			}

			throw $e;
		} finally {
			$entries = glob($directory . '/*');
			array_map(unlink(...), $entries === false ? [] : $entries);
			rmdir($directory);
		}
	}

	/**
	 * What goes into the bundle, read in one transaction.
	 *
	 * @return array{manifest: array<string, mixed>, files: list<ProjectFile>, models: list<ModelAsset>}
	 */
	private function read(Project $project, bool $withFiles): array
	{
		return $this->orm->getTransactionProvider()->transaction(function () use ($project, $withFiles): array {
			// Decoded to objects, so empty ones stay `{}`.
			$snapshot = json_decode($this->snapshotService->createSnapshot($project), flags: JSON_THROW_ON_ERROR);
			$files = $withFiles ? $this->fileRepository->findByProject($project->id) : [];
			$models = $withFiles ? $this->models($project, $snapshot) : [];

			return ['manifest' => $this->manifest($project, $snapshot, $files, $models), 'files' => $files, 'models' => $models];
		});
	}

	/**
	 * Adds a stored object as an entry. ZipArchive reads an added file only when the archive is closed, so each object
	 * goes into a temporary file first instead of a string held until then.
	 */
	private function add(ZipArchive $zip, string $directory, string $entry, string $storageKey, string $mimeType): void
	{
		$temporary = $directory . '/' . bin2hex(random_bytes(8));
		$body = $this->storage->get($storageKey);
		if (file_put_contents($temporary, $body) !== strlen($body) || !$zip->addFile($temporary, $entry)) {
			throw new RuntimeException(sprintf('Cannot add %s to the archive.', $entry));
		}
		if (in_array($mimeType, self::StoredMimeTypes, true)) {
			$zip->setCompressionName($entry, ZipArchive::CM_STORE);
		}
	}

	/**
	 * @param list<ProjectFile> $files
	 * @param list<ModelAsset> $models
	 * @return array<string, mixed>
	 */
	private function manifest(Project $project, mixed $snapshot, array $files, array $models): array
	{
		return [
			'format' => ProjectBundle::Format,
			'kind' => ProjectBundle::Kind,
			'exportedAt' => new DateTimeImmutable()->format(DATE_ATOM),
			'snapshot' => $snapshot,
			'pricing' => [
				'currency' => $project->currency,
				'wastePercent' => $project->wastePercent,
				'prices' => array_map(static fn (ProjectMaterialPrice $price): array => [
					'materialCode' => $price->material?->code,
					'surface' => $price->surface?->value,
					'pricePerArea' => $price->pricePerArea,
					'pricePerLength' => $price->pricePerLength,
				], $this->priceRepository->findByProject($project->id)),
			],
			'definitions' => array_map(static fn (FurnitureDefinition $definition): array => [
				'id' => $definition->id,
				'name' => $definition->name,
				'description' => $definition->description,
				'definition' => json_decode($definition->definition, flags: JSON_THROW_ON_ERROR),
			], $this->definitionRepository->findByProject($project->id)),
			'files' => array_map(static fn (ProjectFile $file): array => [
				'id' => $file->id,
				'filename' => $file->filename,
				'mimeType' => $file->mimeType,
				'path' => ProjectBundle::filePath($file->id, $file->filename),
			], $files),
			'models' => array_map(static fn (ModelAsset $model): array => [
				'id' => $model->id,
				'name' => $model->name,
				'description' => $model->description,
				'license' => $model->license,
				'author' => $model->author,
				'sourceUrl' => $model->sourceUrl,
				'filename' => $model->filename,
				'path' => ProjectBundle::modelPath($model->id),
			], $models),
		];
	}

	/**
	 * @param mixed $snapshot the decoded snapshot (objects)
	 * @return list<ModelAsset> the models the floors' items use (deleted ones too) and the project's own, by id
	 */
	private function models(Project $project, mixed $snapshot): array
	{
		$models = [];
		foreach (self::list($snapshot, 'floors') as $entry) {
			foreach (self::list($entry, 'models') as $model) {
				$id = $model instanceof stdClass ? $model->id ?? null : null;
				$asset = is_int($id) && !isset($models[$id]) ? $this->modelRepository->findById($id) : null;
				if ($asset !== null) {
					$models[$asset->id] = $asset;
				}
			}
		}
		foreach ($this->modelRepository->findByProject($project->id) as $model) {
			$models[$model->id] = $model;
		}
		ksort($models);

		return array_values($models);
	}

	/** @return list<mixed> the list under `$key` of a decoded object, empty when there is none */
	private static function list(mixed $object, string $key): array
	{
		$value = $object instanceof stdClass ? $object->{$key} ?? null : null;

		return is_array($value) ? array_values($value) : [];
	}

	/** @param array<string, mixed> $manifest */
	private function encode(array $manifest): string
	{
		return json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}
}
