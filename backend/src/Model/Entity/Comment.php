<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\CommentAuthorEnum;
use FlatDesigner\Model\Repository\CommentRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A review comment pinned to a point of a floor of the working copy (plan mm, `z` above the floor). A reply has a
 * `parent` (one level deep) and takes its point. Only a thread (a comment without a parent) is resolved, and only a
 * thread has a `number`: counted up per floor, never reused, shown on its pin (the same for everyone who sees it).
 * The author is a user (`createdBy`), an agent (`createdBy` plus the MCP client) or a guest of a share link
 * (`guestName`, `shareLink`). Comments are not floor content: no revision, no undo, not in snapshots.
 */
#[Entity(repositoryClass: CommentRepository::class)]
class Comment extends AEntity
{
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $resolvedAt = null;

	public function __construct(
		#[ManyToOne(entityClass: Project::class)]
		public readonly Project $project,
		#[ManyToOne(entityClass: Floor::class)]
		public Floor $floor,
		#[ManyToOne(entityClass: self::class, nullable: true)]
		public readonly ?Comment $parent,
		#[Column(type: Type::Int, nullable: true)]
		public readonly ?int $number,
		#[Column(type: Type::Int)]
		public readonly int $x,
		#[Column(type: Type::Int)]
		public readonly int $y,
		#[Column(type: Type::Int)]
		public readonly int $z,
		#[Column(type: Type::Text)]
		public string $body,
		#[ColumnEnum(enum: CommentAuthorEnum::class)]
		public readonly CommentAuthorEnum $authorType,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public readonly ?User $createdBy,
		#[Column(type: Type::String, size: 128, nullable: true)]
		public readonly ?string $mcpClientId,
		#[Column(type: Type::String, nullable: true)]
		public readonly ?string $mcpClientName,
		#[Column(type: Type::String, size: 100, nullable: true)]
		public readonly ?string $guestName,
		#[ManyToOne(entityClass: ShareLink::class, nullable: true)]
		public readonly ?ShareLink $shareLink,
	) {
	}
}
