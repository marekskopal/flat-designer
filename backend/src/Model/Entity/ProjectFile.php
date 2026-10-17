<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\ProjectFileRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * File uploaded to a project (floor plans, renders, photos of materials). The bytes live in object
 * storage under `storageKey`; `mimeType` is detected from the content on upload. Files are shared by
 * all versions of the project, they are not part of version snapshots.
 */
#[Entity(repositoryClass: ProjectFileRepository::class)]
class ProjectFile extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Project::class)]
		public readonly Project $project,
		#[Column(type: Type::String)]
		public string $filename,
		#[Column(type: Type::String, size: 191)]
		public readonly string $mimeType,
		#[Column(type: Type::Int)]
		public readonly int $size,
		#[Column(type: Type::String, size: 512)]
		public readonly string $storageKey,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public ?User $uploadedBy,
	) {
	}
}
