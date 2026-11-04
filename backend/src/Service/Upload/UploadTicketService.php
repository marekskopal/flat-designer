<?php

declare(strict_types=1);

namespace Izbik\Service\Upload;

use DateTimeImmutable;
use Izbik\Dto\ModelAssetInputDto;
use Izbik\Dto\ProjectImportDto;
use Izbik\Model\Entity\Enum\FurnitureScopeEnum;
use Izbik\Model\Entity\Enum\UploadKindEnum;
use Izbik\Model\Entity\ModelAsset;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\ProjectFile;
use Izbik\Model\Entity\UploadTicket;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\UploadTicketRepository;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\ModelAsset\ModelAssetProvider;
use Izbik\Service\Project\ProjectFileProvider;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Project\Transfer\ProjectImportConfig;
use Izbik\Service\Project\Transfer\ProjectImporter;
use Izbik\Service\Project\Transfer\ProjectImportException;
use Izbik\Service\Storage\S3Config;
use MarekSkopal\ORM\Database\DatabaseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;
use const JSON_FORCE_OBJECT;
use const JSON_THROW_ON_ERROR;

/**
 * One-time upload URLs for files too large for a tool call. Creating one checks what can be checked before the file
 * arrives (access to the project, a model's fields and scope); redeeming it stores the file through the same provider
 * as the app's upload forms, acting as the user who asked for the URL. Claiming the ticket is an atomic UPDATE, so a
 * token is used once, and it happens before the body is read, so an unknown token costs no memory. A rejected file
 * releases the ticket again, so the agent may send a fixed one to the same URL.
 */
final readonly class UploadTicketService
{
	private const int LifetimeSeconds = 900;

	public function __construct(
		private UploadTicketRepository $ticketRepository,
		private ProjectProvider $projectProvider,
		private ProjectFileProvider $projectFileProvider,
		private ModelAssetProvider $modelProvider,
		private S3Config $s3Config,
		private ProjectImportConfig $importConfig,
		private ProjectImporter $importer,
		private DatabaseInterface $database,
	) {
	}

	/** @return array{UploadTicket, string} the ticket and its token */
	public function createForFile(User $user, Project $project, string $filename): array
	{
		return $this->create($user, UploadKindEnum::ProjectFile, $project, ProjectFileProvider::cleanFilename($filename), []);
	}

	/** @return array{UploadTicket, string} the ticket and its token, for a project file to import as a new project */
	public function createForImport(User $user): array
	{
		return $this->create($user, UploadKindEnum::ProjectImport, null, 'project', []);
	}

	/** @return array{UploadTicket, string} the ticket and its token */
	public function createForModel(User $user, ModelAssetInputDto $input, string $filename): array
	{
		[, $project] = $this->modelProvider->assertUploadable($user, $input);

		return $this->create($user, UploadKindEnum::Model, $project, ModelAssetProvider::filename($filename), [
			'name' => $input->name,
			'description' => $input->description,
			'license' => $input->license,
			'author' => $input->author,
			'sourceUrl' => $input->sourceUrl,
			'scope' => ($input->scope ?? FurnitureScopeEnum::User)->value,
		]);
	}

	/** The path of the upload URL (`Routes::Upload`); the caller prepends the app's base URL. */
	public static function path(string $token): string
	{
		return '/api/uploads/' . $token;
	}

	/** The most bytes an upload may have; a body read past it is rejected by the provider. */
	public function maxBytes(UploadKindEnum $kind = UploadKindEnum::ProjectFile): int
	{
		return $kind === UploadKindEnum::ProjectImport ? $this->importConfig->maxBytes : $this->s3Config->maxFileSizeBytes;
	}

	/** @param StreamInterface $body the request body, read once the ticket is claimed and only up to the kind's limit */
	public function redeem(string $token, StreamInterface $body): ProjectFile|ModelAsset|ProjectImportDto
	{
		$tokenHash = hash('sha256', $token);
		$now = new DateTimeImmutable();
		$claimed = $this->database->execute(
			'UPDATE upload_tickets SET used_at = ?, updated_at = ? WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
			[$now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'), $tokenHash, $now->format('Y-m-d H:i:s')],
			cached: false,
		)->rowCount() === 1;
		$ticket = $claimed ? $this->ticketRepository->findOneByTokenHash($tokenHash) : null;
		if ($ticket === null) {
			throw UploadTicketException::notFound();
		}

		try {
			return match ($ticket->kind) {
				UploadKindEnum::ProjectFile => $this->storeFile($ticket, self::read($body, $this->maxBytes() + 1)),
				UploadKindEnum::Model => $this->storeModel($ticket, self::read($body, $this->maxBytes() + 1)),
				UploadKindEnum::ProjectImport => $this->import($ticket, $body),
			};
		} catch (Throwable $e) {
			$this->database->execute('UPDATE upload_tickets SET used_at = NULL WHERE id = ?', [$ticket->id], cached: false);

			throw $e;
		}
	}

	/**
	 * @param array<string, string> $metadata
	 * @return array{UploadTicket, string}
	 */
	private function create(User $user, UploadKindEnum $kind, ?Project $project, string $filename, array $metadata): array
	{
		$now = new DateTimeImmutable();
		$this->database->execute('DELETE FROM upload_tickets WHERE expires_at < ?', [$now->format('Y-m-d H:i:s')], cached: false);

		$token = bin2hex(random_bytes(32));
		$ticket = new UploadTicket(
			tokenHash: hash('sha256', $token),
			user: $user,
			kind: $kind,
			project: $project,
			filename: $filename,
			metadata: json_encode($metadata, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR),
			expiresAt: $now->modify(sprintf('+%d seconds', self::LifetimeSeconds)),
		);
		$ticket->createdAt = $now;
		$ticket->updatedAt = $now;
		$this->ticketRepository->persist($ticket);

		return [$ticket, $token];
	}

	/** The project file goes to a temporary file, as it may be larger than PHP's memory: the importer reads it from there. */
	private function import(UploadTicket $ticket, StreamInterface $body): ProjectImportDto
	{
		$path = tempnam(sys_get_temp_dir(), 'izbik-import');
		$file = $path === false ? false : fopen($path, 'wb');
		if ($path === false || $file === false) {
			throw new RuntimeException('Cannot create a temporary file.');
		}

		try {
			$limit = $this->maxBytes(UploadKindEnum::ProjectImport);
			$written = 0;
			while (!$body->eof() && $written <= $limit) {
				$chunk = $body->read(1 << 20);
				if ($chunk === '') {
					break;
				}
				fwrite($file, $chunk);
				$written += strlen($chunk);
			}
			fclose($file);
			if ($written > $limit) {
				throw new ProjectImportException(sprintf('The project file is larger than %d MB.', intdiv($limit, 1024 * 1024)), 413);
			}

			return $this->importer->import($ticket->user, $path);
		} finally {
			if (is_file($path)) {
				unlink($path);
			}
		}
	}

	/** At most `$limit` bytes of the stream; one byte over the upload limit is enough for the provider to reject it. */
	private static function read(StreamInterface $stream, int $limit): string
	{
		$body = '';
		while (strlen($body) < $limit && !$stream->eof()) {
			$chunk = $stream->read(min(1 << 20, $limit - strlen($body)));
			if ($chunk === '') {
				break;
			}
			$body .= $chunk;
		}

		return $body;
	}

	private function storeFile(UploadTicket $ticket, string $body): ProjectFile
	{
		// The user may have lost access since (a project handed over, an admin demoted).
		$project = $ticket->project === null ? null : $this->projectProvider->findAccessible($ticket->user, $ticket->project->id);
		if ($project === null) {
			throw UploadTicketException::accessLost();
		}

		return $this->projectFileProvider->upload($ticket->user, $project, $ticket->filename, $body);
	}

	private function storeModel(UploadTicket $ticket, string $body): ModelAsset
	{
		$metadata = array_filter(JsonDecoder::decodeObject($ticket->metadata), is_string(...));
		$input = new ModelAssetInputDto(
			name: $metadata['name'] ?? '',
			description: $metadata['description'] ?? '',
			license: $metadata['license'] ?? '',
			author: $metadata['author'] ?? '',
			sourceUrl: $metadata['sourceUrl'] ?? '',
			scope: FurnitureScopeEnum::tryFrom($metadata['scope'] ?? '') ?? FurnitureScopeEnum::User,
			projectId: $ticket->project?->id,
		);

		return $this->modelProvider->upload($ticket->user, $input, $ticket->filename, $body);
	}
}
