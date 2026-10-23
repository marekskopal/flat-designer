<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Repository\PlotRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * The land a floor's building stands on: its boundary in plan mm and the ground inside it (a lawn when no material
 * is set). Paths, terraces and gardens on it are rooms.
 */
#[Entity(repositoryClass: PlotRepository::class)]
class Plot extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	/** mm the ground lies above (+) or below (−) the floor's level; the building's slab shows above a lower ground. */
	#[Column(type: Type::Int)]
	public int $groundLevel = 0;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
		/** JSON `[[x, y], …]`, plan mm. */
		#[Column(type: Type::Text)]
		public string $polygon,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $groundMaterial = null,
	) {
	}
}
