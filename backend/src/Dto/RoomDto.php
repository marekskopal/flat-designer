<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Service\Json\JsonDecoder;

final readonly class RoomDto
{
	/** @param list<array{int, int}> $polygon */
	public function __construct(
		public int $id,
		public string $code,
		public string $name,
		public RoomTypeEnum $type,
		public array $polygon,
		public float $area,
		public ?int $ceilingHeight,
		public ?int $floorMaterialId,
	) {
	}

	public static function fromEntity(Room $room): self
	{
		$polygon = JsonDecoder::decodePolygon($room->polygon);

		return new self(
			id: $room->id,
			code: $room->code,
			name: $room->name,
			type: $room->type,
			polygon: $polygon,
			area: round(self::polygonArea($polygon) / 1000000, 2),
			ceilingHeight: $room->ceilingHeight,
			floorMaterialId: $room->floorMaterial?->id,
		);
	}

	/**
	 * Shoelace formula, mm².
	 *
	 * @param list<array{int, int}> $polygon
	 */
	private static function polygonArea(array $polygon): float
	{
		$sum = 0;
		$count = count($polygon);
		foreach ($polygon as $i => [$x, $y]) {
			[$nextX, $nextY] = $polygon[($i + 1) % $count];
			$sum += $x * $nextY - $nextX * $y;
		}

		return abs($sum) / 2;
	}
}
