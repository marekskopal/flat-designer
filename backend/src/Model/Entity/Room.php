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
 * `{materialId, bottom, top}` (mm above the floor) lining the room's walls, e.g. tiling; doors are left
 * free. Above the bands the walls keep their own material. `tileOriginX/Y` is a plan point where tile joints
 * cross: the floor tiles, the wall bands and tiled items of the room share that grid (null: the plan origin).
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
