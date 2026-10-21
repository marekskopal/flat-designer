<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Upload;

use Closure;
use DateTimeImmutable;
use FlatDesigner\Dto\ModelAssetInputDto;
use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Entity\Enum\UploadKindEnum;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectFile;
use FlatDesigner\Model\Entity\UploadTicket;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\UploadTicketRepository;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\ModelAsset\ModelAssetProvider;
use FlatDesigner\Service\Project\ProjectFileProvider;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Storage\S3Config;
use MarekSkopal\ORM\Database\DatabaseInterface;
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
		private DatabaseInterface $database,
	) {
	}

	/** @return array{UploadTicket, string} the ticket and its token */
	public function createForFile(User $user, Project $project, string $filename): array
	{
		return $this->create($user, UploadKindEnum::ProjectFile, $project, ProjectFileProvider::cleanFilename($filename), []);
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
	public function maxBytes(): int
	{
		return $this->s3Config->maxFileSizeBytes;
	}

	/** @param Closure(): string $readBody reads the request body, called once the ticket is claimed */
	public function redeem(string $token, Closure $readBody): ProjectFile|ModelAsset
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
			$body = $readBody();

			return $ticket->kind === UploadKindEnum::ProjectFile ? $this->storeFile($ticket, $body) : $this->storeModel($ticket, $body);
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
