<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;

/**
 * One room of the schedule: net floor area (m²) and perimeter (m) of its outline on the inner wall faces, the clear
 * height (mm, null for an open-air room) and the volume (m³, null without a ceiling).
 */
final readonly class RoomScheduleRowDto
{
	public function __construct(
		public int $id,
		public string $code,
		public string $name,
		public RoomTypeEnum $type,
		public float $area,
		public float $perimeter,
		public ?int $clearHeight,
		public ?float $volume,
		public bool $outdoor,
	) {
	}

	public static function fromRoom(RoomDto $room): self
	{
		return new self(
			id: $room->id,
			code: $room->code,
			name: $room->name,
			type: $room->type,
			area: $room->area,
			perimeter: $room->perimeter,
			clearHeight: $room->ceilingHeight,
			volume: $room->ceilingHeight === null ? null : round($room->area * $room->ceilingHeight / 1000, 2),
			outdoor: $room->type->isOutdoor(),
		);
	}
}
