<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Service\Project\FloorStack;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * One storey of a project, holding its rooms, walls and items. All floors share the plan coordinates, so they stack.
 * `level` orders the floors (0 = ground floor, negative below ground); `elevation` is the finished floor level above the
 * project datum ±0.000 and `slabThickness` the slab under the floor, both mm (`FloorStack`).
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
		#[Column(type: Type::Int, default: 0)]
		public int $elevation,
		#[Column(type: Type::Int, default: FloorStack::GroundSlab)]
		public int $slabThickness,
	) {
	}
}
