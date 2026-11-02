<?php

declare(strict_types=1);

namespace Izbik\Model\Entity;

use DateTimeImmutable;
use Izbik\Model\Entity\Enum\GutterProfileEnum;
use Izbik\Model\Entity\Enum\RidgeStyleEnum;
use Izbik\Model\Entity\Enum\RoofShapeEnum;
use Izbik\Model\Repository\RoofRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A roof over a floor: a rectangle `width` × `depth` centred on (x, y), plan mm, turned by `rotation` (degrees
 * counter-clockwise; the ridge runs along the width, east–west at 0). The rectangle is the eaves line, where the roof's
 * underside is `eaveHeight` above the floor's level; the planes rise from it at `pitch` and reach `overhang` past it at
 * the eaves and `vergeOverhang` past the gables, whose verges `startSkew` / `endSkew` may turn. `thickness` is the rafters and covering, square to the slope. The
 * geometry is `RoofGeometry` (twin of `viewer/engine/roof-geometry.ts`).
 */
#[Entity(repositoryClass: RoofRepository::class)]
class Roof extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	/** Gutters along the eaves of a pitched roof. */
	#[ColumnEnum(enum: GutterProfileEnum::class)]
	public GutterProfileEnum $gutters = GutterProfileEnum::None;

	/** The gutters' and downpipes' material; null titanium zinc. */
	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $gutterMaterial = null;

	/** JSON `[{edge, along}]`: downpipes on eaves edges (`RoofEdgeEnum`), mm from the roof's centre line; null at the eaves' corners. */
	#[Column(type: Type::Text, nullable: true)]
	public ?string $downpipes = null;

	/** Rows of snow guards on each pitched plane, from the eaves up. */
	#[Column(type: Type::Int)]
	public int $snowGuards = 0;

	#[ColumnEnum(enum: RidgeStyleEnum::class)]
	public RidgeStyleEnum $ridgeStyle = RidgeStyleEnum::Box;

	/**
	 * A gable's or shed's verge at the ridge's start (−s) and end, degrees counter-clockwise, 0 square to the ridge (`RoofSkew`).
	 */
	#[Column(type: Type::Int)]
	public int $startSkew = 0;

	#[Column(type: Type::Int)]
	public int $endSkew = 0;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: RoofShapeEnum::class)]
		public RoofShapeEnum $shape,
		#[Column(type: Type::Int)]
		public int $x,
		#[Column(type: Type::Int)]
		public int $y,
		#[Column(type: Type::Int)]
		public int $rotation,
		#[Column(type: Type::Int)]
		public int $width,
		#[Column(type: Type::Int)]
		public int $depth,
		#[Column(type: Type::Int)]
		public int $eaveHeight,
		/** Degrees; a flat roof keeps it unused. */
		#[Column(type: Type::Int)]
		public int $pitch,
		#[Column(type: Type::Int)]
		public int $overhang,
		#[Column(type: Type::Int)]
		public int $vergeOverhang,
		#[Column(type: Type::Int)]
		public int $thickness,
		/** A flat roof's parapet, mm above the roof; null none. */
		#[Column(type: Type::Int, nullable: true)]
		public ?int $parapet = null,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $coveringMaterial = null,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $soffitMaterial = null,
	) {
	}
}
