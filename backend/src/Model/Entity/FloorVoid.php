<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Repository\FloorVoidRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A hole through a floor (FD-90): an outline in plan mm cut out of the floor's slab and room floors and out of the
 * ceilings of the floor below, open to it (a gallery's void, a double-height hall). Its edges may be lined.
 */
#[Entity(repositoryClass: FloorVoidRepository::class)]
class FloorVoid extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	/** mm the lining reaches down from the floor's level; null: down to the ceilings below (the whole well). */
	#[Column(type: Type::Int, nullable: true)]
	public ?int $liningDepth = null;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
		/** JSON `[[x, y], …]`, plan mm. */
		#[Column(type: Type::Text)]
		public string $polygon,
		/** The boards lining its edges; null: none (the slab's edge shows). */
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $liningMaterial = null,
	) {
	}
}
