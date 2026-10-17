<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\ProjectRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A user's design of one dwelling: 1..N floors (the editable working copy) plus saved versions.
 * `owner` is null only for projects seeded by migrations until `admin:create` claims them.
 */
#[Entity(repositoryClass: ProjectRepository::class)]
class Project extends AEntity
{
	/** Bumped by every change of the floors; clients send it back to detect concurrent edits. */
	#[Column(type: Type::Int, default: 0)]
	public int $revision = 0;

	public function __construct(
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public ?User $owner,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::String, nullable: true)]
		public ?string $address,
		#[Column(type: Type::Text, nullable: true)]
		public ?string $description,
	) {
	}
}
