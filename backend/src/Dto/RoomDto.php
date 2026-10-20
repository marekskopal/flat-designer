<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Json\JsonDecoder;

final readonly class RoomDto
{
	/**
	 * @param list<array{int, int}> $polygon
	 * @param list<WallFinishDto> $wallFinishes
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
		public ?int $ceilingMaterialId,
		public ?int $vaultSpacing,
		public int $vaultAngle,
		public int $vaultRise,
		public int $beamWidth,
		public int $beamDrop,
		public ?int $beamMaterialId,
		public ?int $skirtingHeight,
		public ?int $skirtingMaterialId,
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
				fn (array $band): WallFinishDto => new WallFinishDto($band['materialId'], $band['bottom'], $band['top']),
				JsonDecoder::decodeWallFinishes($room->wallFinishes),
			),
			tileOriginX: $room->tileOriginX,
			tileOriginY: $room->tileOriginY,
			ceilingMaterialId: $room->ceilingMaterial?->id,
			vaultSpacing: $room->vaultSpacing,
			vaultAngle: $room->vaultAngle,
			vaultRise: $room->vaultRise,
			beamWidth: $room->beamWidth,
			beamDrop: $room->beamDrop,
			beamMaterialId: $room->beamMaterial?->id,
			skirtingHeight: $room->skirtingHeight,
			skirtingMaterialId: $room->skirtingMaterial?->id,
		);
	}
}
