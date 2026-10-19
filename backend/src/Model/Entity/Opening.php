<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\DoorHandleEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Repository\OpeningRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Door, passage or window cut into a wall. `offset` is the distance (mm) from the wall start to the near edge
 * of the opening, measured along the centre line. `side` is a side of the wall (left/right when walking from
 * start to end) and `hinge` an edge of the opening (nearer the wall start or end); their meaning depends on
 * the type:
 * - `Door`: the leaf hangs on `hinge` and swings into `side`; `leafWidth` is the nominal leaf width.
 * - `DoubleDoor`: two leaves hinged on the jambs swing into `side`; `leafWidth` is the active leaf, the one on
 *   `hinge` (none: equal halves).
 * - `SlidingDoor`: the leaf hangs on the `side` face and slides open towards `hinge`.
 * - `PocketDoor`: the leaf slides into a pocket in the wall towards `hinge`.
 * - `Passage`: no leaf.
 * `frameStyle` and `handle` (none: the type's default) dress doors. `entrance` marks the main door of the
 * floor (where a walkthrough starts); `walkable` a window down to the floor (French window) whose two sashes
 * open in the walkthrough (into `side`), so it can be walked through like a door.
 */
#[Entity(repositoryClass: OpeningRepository::class)]
class Opening extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	#[Column(type: Type::Boolean)]
	public bool $entrance = false;

	#[Column(type: Type::Boolean)]
	public bool $walkable = false;

	#[ColumnEnum(enum: DoorFrameStyleEnum::class)]
	public DoorFrameStyleEnum $frameStyle = DoorFrameStyleEnum::Lining;

	#[ColumnEnum(enum: DoorHandleEnum::class, nullable: true)]
	public ?DoorHandleEnum $handle = null;

	public function __construct(
		#[ManyToOne(entityClass: Wall::class)]
		public readonly Wall $wall,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: OpeningTypeEnum::class)]
		public OpeningTypeEnum $type,
		#[Column(type: Type::Int)]
		public int $offset,
		#[Column(type: Type::Int)]
		public int $width,
		#[Column(type: Type::Int)]
		public int $height,
		#[Column(type: Type::Int)]
		public int $sillHeight,
		#[Column(type: Type::Int, nullable: true)]
		public ?int $leafWidth,
		#[ColumnEnum(enum: OpeningHingeEnum::class, nullable: true)]
		public ?OpeningHingeEnum $hinge,
		#[ColumnEnum(enum: OpeningSideEnum::class, nullable: true)]
		public ?OpeningSideEnum $side,
	) {
	}
}
