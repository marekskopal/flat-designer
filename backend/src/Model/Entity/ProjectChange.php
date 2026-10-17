<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\ActorTypeEnum;
use FlatDesigner\Model\Entity\Enum\ChangeOperationEnum;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Repository\ProjectChangeRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * One edit of a floor element, with the element's state (JSON, as served in the scene) before and after.
 * The actor is the user plus, for agents, the MCP client; each actor undoes and redoes only their own
 * changes. `undoneAt` marks an undone change (redo candidate); `closed` changes can no longer be undone
 * or redone (redo history dropped by a newer change, or a version restore).
 */
#[Entity(repositoryClass: ProjectChangeRepository::class)]
class ProjectChange extends AEntity
{
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $undoneAt = null;

	#[Column(type: Type::Boolean, default: false)]
	public bool $closed = false;

	/** Shared by the changes of one operation on several elements (e.g. moving a group); they undo and redo together. */
	#[Column(type: Type::String, size: 32, nullable: true)]
	public ?string $batch = null;

	public function __construct(
		#[ManyToOne(entityClass: Project::class)]
		public readonly Project $project,
		#[ManyToOne(entityClass: Floor::class, nullable: true)]
		public readonly ?Floor $floor,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public readonly ?User $user,
		#[ColumnEnum(enum: ActorTypeEnum::class)]
		public readonly ActorTypeEnum $actorType,
		#[Column(type: Type::String, size: 128, nullable: true)]
		public readonly ?string $mcpClientId,
		#[Column(type: Type::String, nullable: true)]
		public readonly ?string $mcpClientName,
		#[ColumnEnum(enum: ChangeOperationEnum::class)]
		public readonly ChangeOperationEnum $operation,
		#[ColumnEnum(enum: ElementTypeEnum::class)]
		public readonly ElementTypeEnum $elementType,
		#[Column(type: Type::Int)]
		public readonly int $elementId,
		#[Column(type: Type::String)]
		public readonly string $summary,
		#[Column(type: Type::LongText, nullable: true)]
		public readonly ?string $before,
		#[Column(type: Type::LongText, nullable: true)]
		public readonly ?string $after,
		#[Column(type: Type::Int)]
		public int $revision,
	) {
	}
}
