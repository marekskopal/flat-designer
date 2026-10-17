<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\ProjectVersionRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Immutable snapshot of a project's floors, numbered 1, 2, … per project. `snapshot` is the JSON written
 * by `ProjectSnapshotService`; `createdBy` becomes null when that user is deleted.
 */
#[Entity(repositoryClass: ProjectVersionRepository::class)]
class ProjectVersion extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Project::class)]
		public readonly Project $project,
		#[Column(type: Type::Int)]
		public readonly int $number,
		#[Column(type: Type::String, nullable: true)]
		public ?string $note,
		#[Column(type: Type::LongText)]
		public readonly string $snapshot,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public ?User $createdBy,
	) {
	}
}
