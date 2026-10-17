<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\FloorRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * One storey of a project, holding its rooms, walls and items in its own plan coordinates. `level` orders
 * the floors (0 = ground floor, negative below ground).
 */
#[Entity(repositoryClass: FloorRepository::class)]
class Floor extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Project::class)]
		public readonly Project $project,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::Int, default: 0)]
		public int $level,
	) {
	}
}
