<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Repository\SectionLineRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A section line (FD-94): a cut through the building drawn on a floor's plan, from start to end in plan mm, looking to
 * the left of start → end and `depth` mm deep (null: all that lies beyond). The building section is drawn from it.
 */
#[Entity(repositoryClass: SectionLineRepository::class)]
class SectionLine extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	/** mm drawn beyond the cut; null: everything. */
	#[Column(type: Type::Int, nullable: true)]
	public ?int $depth = null;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::Int)]
		public int $startX,
		#[Column(type: Type::Int)]
		public int $startY,
		#[Column(type: Type::Int)]
		public int $endX,
		#[Column(type: Type::Int)]
		public int $endY,
	) {
	}
}
