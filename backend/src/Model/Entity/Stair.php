<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\RailingStyleEnum;
use FlatDesigner\Model\Entity\Enum\StairConstructionEnum;
use FlatDesigner\Model\Entity\Enum\StairRailingEnum;
use FlatDesigner\Model\Entity\Enum\StairShapeEnum;
use FlatDesigner\Model\Entity\Enum\StairTurnEnum;
use FlatDesigner\Model\Repository\StairRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A stair from its floor up to the next one (FD-37). (x, y) is the middle of the first step's front edge, plan mm, and
 * `rotation` the walking-up direction of the first flight (degrees counter-clockwise, 0 walks up north, +y). The rise
 * is the floors' (`StairRise`); `risers` null splits it into as few steps as keep each at most 180 mm. `width` is a
 * flight's, or a spiral's diameter. The stair cuts a void through the floor above as large as its footprint.
 */
#[Entity(repositoryClass: StairRepository::class)]
class Stair extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	/** A rise of its own, mm (FD-87): the stair does not climb to the next floor (garden steps, deck steps); null: it does. */
	#[Column(type: Type::Int, nullable: true)]
	public ?int $rise = null;

	/** Where its first riser stands, mm against the floor's level. */
	#[Column(type: Type::Int)]
	public int $baseZ = 0;

	/** The railing's infill (FD-90); null: posts and a handrail only. */
	#[ColumnEnum(enum: RailingStyleEnum::class, nullable: true)]
	public ?RailingStyleEnum $railingStyle = null;

	/** The railing's; null: the structure's. */
	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $railingMaterial = null;

	/** The sides with a handrail on brackets on the wall beside the flight, `RailHeight` over the nosings. */
	#[ColumnEnum(enum: StairRailingEnum::class)]
	public StairRailingEnum $wallHandrail = StairRailingEnum::None;

	/** The boards lining the void it cuts through the floor above; null: none. */
	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $voidLiningMaterial = null;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: StairShapeEnum::class)]
		public StairShapeEnum $shape,
		#[Column(type: Type::Int)]
		public int $x,
		#[Column(type: Type::Int)]
		public int $y,
		#[Column(type: Type::Int)]
		public int $rotation,
		#[Column(type: Type::Int)]
		public int $width,
		#[Column(type: Type::Int)]
		public int $going,
		#[Column(type: Type::Int, nullable: true)]
		public ?int $risers,
		/** L and U: risers up to the landing; null halves them. */
		#[Column(type: Type::Int, nullable: true)]
		public ?int $firstFlight,
		#[ColumnEnum(enum: StairTurnEnum::class)]
		public StairTurnEnum $turn,
		/** U: mm between the two flights. */
		#[Column(type: Type::Int)]
		public int $gap,
		#[ColumnEnum(enum: StairConstructionEnum::class)]
		public StairConstructionEnum $construction,
		#[ColumnEnum(enum: StairRailingEnum::class)]
		public StairRailingEnum $railing,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $treadMaterial = null,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $structureMaterial = null,
	) {
	}
}
