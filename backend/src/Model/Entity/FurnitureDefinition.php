<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Repository\FurnitureDefinitionRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A custom piece of furniture as data: `definition` is the JSON (format 1) of parameters and parts evaluated by
 * `Service\Furniture\DefinitionEvaluator` (twin in the viewer). The scope follows from the owners: `user` = that user's
 * library, `project` = one project, neither = global. Items will reference it live, so it is soft-deleted.
 */
#[Entity(repositoryClass: FurnitureDefinitionRepository::class)]
class FurnitureDefinition extends AEntity
{
	/** Set when deleted; the row stays so items using it keep rendering, but it cannot be picked any more. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	public function __construct(
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public readonly ?User $user,
		#[ManyToOne(entityClass: Project::class, nullable: true)]
		public readonly ?Project $project,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::Text)]
		public string $description,
		#[Column(type: Type::Text)]
		public string $definition,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public ?User $createdBy,
	) {
	}

	public function scope(): FurnitureScopeEnum
	{
		return match (true) {
			$this->project !== null => FurnitureScopeEnum::Project,
			$this->user !== null => FurnitureScopeEnum::User,
			default => FurnitureScopeEnum::Global,
		};
	}
}
