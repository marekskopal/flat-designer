<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\RoomTypeEnum;
use Izbik\Model\Entity\Room;
use Izbik\Service\Editor\Geometry\PlanMath;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\Json\RoomZonesDecoder;

final readonly class RoomDto
{
	/**
	 * @param list<array{int, int}> $polygon
	 * @param list<WallFinishDto> $wallFinishes
	 * @param list<array{polygon: list<array{int, int}>, materialId: int}> $floorZones another floor material over part of the floor
	 * @param list<array{polygon: list<array{int, int}>, height: int, materialId: int|null}> $ceilingZones dropped ceilings over part of the
	 *   room, `height` mm above its floor
	 */
	public function __construct(
		public int $id,
		public string $code,
		public string $name,
		public RoomTypeEnum $type,
		public array $polygon,
		public float $area,
		public float $perimeter,
		public ?int $ceilingHeight,
		public ?int $floorMaterialId,
		public array $wallFinishes,
		public ?int $tileOriginX,
		public ?int $tileOriginY,
		public int $floorAngle,
		public int $floorElevation,
		public ?int $ceilingMaterialId,
		public ?int $vaultSpacing,
		public int $vaultAngle,
		public int $vaultRise,
		public int $beamWidth,
		public int $beamDrop,
		public ?int $beamMaterialId,
		public ?int $skirtingHeight,
		public ?int $skirtingMaterialId,
		public ?int $soffitMaterialId = null,
		public array $floorZones = [],
		public array $ceilingZones = [],
	) {
	}

	public static function fromEntity(Room $room): self
	{
		$polygon = JsonDecoder::decodePolygon($room->polygon);
		$points = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $polygon);

		return new self(
			id: $room->id,
			code: $room->code,
			name: $room->name,
			type: $room->type,
			polygon: $polygon,
			area: round(abs(PlanMath::signedArea($points)) / 1000000, 2),
			perimeter: round(PlanMath::perimeter($points) / 1000, 2),
			ceilingHeight: $room->ceilingHeight,
			floorMaterialId: $room->floorMaterial?->id,
			wallFinishes: $room->wallFinishes === null ? [] : array_map(
				WallFinishDto::fromArray(...),
				JsonDecoder::decodeWallFinishes($room->wallFinishes),
			),
			tileOriginX: $room->tileOriginX,
			tileOriginY: $room->tileOriginY,
			floorAngle: $room->floorAngle,
			floorElevation: $room->floorElevation,
			ceilingMaterialId: $room->ceilingMaterial?->id,
			vaultSpacing: $room->vaultSpacing,
			vaultAngle: $room->vaultAngle,
			vaultRise: $room->vaultRise,
			beamWidth: $room->beamWidth,
			beamDrop: $room->beamDrop,
			beamMaterialId: $room->beamMaterial?->id,
			skirtingHeight: $room->skirtingHeight,
			skirtingMaterialId: $room->skirtingMaterial?->id,
			soffitMaterialId: $room->soffitMaterial?->id,
			floorZones: $room->floorZones === null ? [] : RoomZonesDecoder::floorZones($room->floorZones),
			ceilingZones: $room->ceilingZones === null ? [] : RoomZonesDecoder::ceilingZones($room->ceilingZones),
		);
	}
}
