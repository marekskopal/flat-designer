<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\UploadKindEnum;
use FlatDesigner\Model\Repository\UploadTicketRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A one-time upload URL (`PUT /api/uploads/{token}`) handed to an MCP agent, so it can send a file too large to pass as
 * base64 in a tool call. The token is stored as its SHA-256 hash. The upload acts as `user`; `metadata` is the JSON of
 * the model's describing fields (empty for a project file). A ticket is used once, until `expiresAt`.
 */
#[Entity(repositoryClass: UploadTicketRepository::class)]
class UploadTicket extends AEntity
{
	public function __construct(
		#[Column(type: Type::String, size: 64)]
		public readonly string $tokenHash,
		#[ManyToOne(entityClass: User::class)]
		public readonly User $user,
		#[ColumnEnum(enum: UploadKindEnum::class)]
		public readonly UploadKindEnum $kind,
		#[ManyToOne(entityClass: Project::class, nullable: true)]
		public readonly ?Project $project,
		#[Column(type: Type::String)]
		public readonly string $filename,
		#[Column(type: Type::Text)]
		public readonly string $metadata,
		#[Column(type: Type::Timestamp)]
		public readonly DateTimeImmutable $expiresAt,
		#[Column(type: Type::Timestamp, nullable: true)]
		public ?DateTimeImmutable $usedAt = null,
	) {
	}
}
