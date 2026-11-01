<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\BoardDirectionEnum;
use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\DoorHandleEnum;
use FlatDesigner\Model\Entity\Enum\DoorLeafStyleEnum;
use FlatDesigner\Model\Entity\Enum\GarageDoorStyleEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\ShutterStyleEnum;
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
 * `frameStyle` and `handle` (none: the type's default) dress doors, `leafStyle` with `leafPanels` (panels or
 * panes) their leaves. A window is divided by `mullions` (vertical
 * bars; 1 = two panes) and `transoms` (horizontal bars); `sillBoard` is how far its inner sill board reaches past
 * the wall face on `side`, the interior (none: no boards; exterior walls also get an outer sill). `entrance` marks the main door of the
 * floor (where a walkthrough starts); `walkable` a window down to the floor (French window) whose two sashes
 * open in the walkthrough (into `side`), so it can be walked through like a door. A window's `panelHeight` is a
 * solid lower part of its glazing (mm from the sill), and `shutters` are two leaves on the exterior face (opposite
 * `side`), folded back against the wall or `shuttersClosed`, in `shutterMaterial` (none: painted timber). A `Niche` is a
 * recess `depth` mm deep into the `side` face; the rest of the wall stays behind it.
 *
 * FD-89: `frameMaterial` dresses the frame and sashes, `outsideFrameMaterial` a window's outer face (timber-aluminium),
 * `leafMaterial` the door leaves (none: the defaults, white doors and anthracite windows). A `Boarded` leaf runs its
 * boards `boardDirection`. A `GarageDoor` opens by its `garageDoorStyle`; a `SlidingDoor` with `fixedPanes` is a
 * lift-and-slide door: the opening divided into that many fixed panes and one sliding over its neighbour towards
 * `hinge`, inside the opening. `inset` sets a window's or door's frame that far (mm) from the outer face, opposite
 * `side` (none: centred, or a door's lining across the wall).
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

	#[Column(type: Type::Int)]
	public int $mullions = 1;

	#[Column(type: Type::Int)]
	public int $transoms = 0;

	#[Column(type: Type::Int, nullable: true)]
	public ?int $sillBoard = null;

	#[ColumnEnum(enum: DoorLeafStyleEnum::class)]
	public DoorLeafStyleEnum $leafStyle = DoorLeafStyleEnum::Flush;

	#[Column(type: Type::Int)]
	public int $leafPanels = 2;

	#[Column(type: Type::Int, nullable: true)]
	public ?int $panelHeight = null;

	#[ColumnEnum(enum: ShutterStyleEnum::class, nullable: true)]
	public ?ShutterStyleEnum $shutters = null;

	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $shutterMaterial = null;

	#[Column(type: Type::Boolean)]
	public bool $shuttersClosed = false;

	#[Column(type: Type::Int, nullable: true)]
	public ?int $depth = null;

	/** The reveal (jambs and head across the wall) is lined with the outer face's band (FD-88: a clad façade). */
	#[Column(type: Type::Boolean)]
	public bool $revealLining = false;

	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $frameMaterial = null;

	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $outsideFrameMaterial = null;

	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $leafMaterial = null;

	#[ColumnEnum(enum: BoardDirectionEnum::class)]
	public BoardDirectionEnum $boardDirection = BoardDirectionEnum::Vertical;

	#[ColumnEnum(enum: GarageDoorStyleEnum::class, nullable: true)]
	public ?GarageDoorStyleEnum $garageDoorStyle = null;

	#[Column(type: Type::Int)]
	public int $fixedPanes = 0;

	#[Column(type: Type::Int, nullable: true)]
	public ?int $inset = null;

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
