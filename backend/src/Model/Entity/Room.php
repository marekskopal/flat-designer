<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Model\Repository\RoomRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Floor area of one room. `polygon` is a JSON list of [x, y] points in millimetres (plan coordinates,
 * origin at the inner south-west corner of the floor, y pointing north). `ceilingHeight` is null for
 * open-air areas (terrace). `wallFinishes` is an optional JSON list of horizontal bands
 * `{materialId, bottom, top}` (mm above the floor) lining the room's walls, e.g. tiling; doors are left free. A wall
 * face may have bands of its own instead (`Wall::$leftFinishes`). Above the bands the walls keep their own material. `tileOriginX/Y` is a plan point where tile joints
 * cross: the floor tiles, the wall bands and tiled items of the room share that grid (null: the plan origin).
 * `floorAngle` turns the floor's pattern (planks, tiles) about that point, degrees counter-clockwise; 0 lays planks
 * along plan y. It turns only the level faces, so the wall bands keep their own joints.
 *
 * The ceiling is in `ceilingMaterial` (null: white plaster). With `vaultSpacing` it is vaulted: shallow vaults
 * `vaultRise` high spring between parallel beams `vaultSpacing` apart, running at `vaultAngle` degrees
 * (counter-clockwise from plan x). Their crowns are at `ceilingHeight`; the beams, `beamWidth` wide, hang `beamDrop`
 * below the springing, in `beamMaterial` (null: white paint). `skirtingHeight` (null: none) puts skirting boards
 * in `skirtingMaterial` along the room's walls, leaving the doors free.
 */
#[Entity(repositoryClass: RoomRepository::class)]
class Room extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	#[Column(type: Type::Int, nullable: true)]
	public ?int $tileOriginX = null;

	#[Column(type: Type::Int, nullable: true)]
	public ?int $tileOriginY = null;

	#[Column(type: Type::Int)]
	public int $floorAngle = 0;

	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $ceilingMaterial = null;

	#[Column(type: Type::Int, nullable: true)]
	public ?int $vaultSpacing = null;

	#[Column(type: Type::Int)]
	public int $vaultAngle = 0;

	#[Column(type: Type::Int)]
	public int $vaultRise = 130;

	#[Column(type: Type::Int)]
	public int $beamWidth = 80;

	#[Column(type: Type::Int)]
	public int $beamDrop = 30;

	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $beamMaterial = null;

	#[Column(type: Type::Int, nullable: true)]
	public ?int $skirtingHeight = null;

	#[ManyToOne(entityClass: Material::class, nullable: true)]
	public ?Material $skirtingMaterial = null;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String, size: 32)]
		public string $code,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: RoomTypeEnum::class)]
		public RoomTypeEnum $type,
		#[Column(type: Type::Text)]
		public string $polygon,
		#[Column(type: Type::Int, nullable: true)]
		public ?int $ceilingHeight,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $floorMaterial,
		#[Column(type: Type::Text, nullable: true)]
		public ?string $wallFinishes,
	) {
	}
}
