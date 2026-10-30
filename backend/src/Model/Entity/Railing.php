<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\HandrailProfileEnum;
use FlatDesigner\Model\Entity\Enum\RailingStyleEnum;
use FlatDesigner\Model\Repository\RailingRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A railing (FD-90): a balustrade along a polyline in plan mm (a void's or gallery's edge, a terrace, the top of a
 * retaining wall), `height` mm from its base at `baseZ` against the floor's level to the top of its handrail. Posts
 * stand at its corners and at most `postSpacing` apart; the `style` fills between them.
 */
#[Entity(repositoryClass: RailingRepository::class)]
class Railing extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	#[Column(type: Type::Int)]
	public int $baseZ = 0;

	/** mm, the balusters' clear gap or the rods' and cables' pitch; null: the style's (`RailingStyleEnum::defaultSpacing()`). */
	#[Column(type: Type::Int, nullable: true)]
	public ?int $spacing = null;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
		/** JSON `[[x, y], …]`, plan mm, two points at least. */
		#[Column(type: Type::Text)]
		public string $points,
		#[Column(type: Type::Int)]
		public int $height,
		#[ColumnEnum(enum: RailingStyleEnum::class)]
		public RailingStyleEnum $style,
		#[Column(type: Type::Int)]
		public int $postSpacing,
		#[ColumnEnum(enum: HandrailProfileEnum::class)]
		public HandrailProfileEnum $handrail,
		/** Posts, handrail, balusters, rods; null: brushed steel. */
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $material = null,
		/** A glass pane's or panel's; null: clear glass, or the railing's material. */
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $infillMaterial = null,
	) {
	}
}
