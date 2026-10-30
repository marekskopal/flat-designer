<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\RoomTypeEnum;
use FlatDesigner\Service\Editor\Geometry\RoomUnderRoof;

/**
 * One room of the schedule: net floor area (m²) and perimeter (m) of its outline on the inner wall faces, the clear
 * height (mm, null for an open-air room) and the volume (m³, null without a ceiling). Under a roof (FD-38) the clear
 * height is the highest, `minClearHeight` the lowest (at the eaves), the volume follows the slope and `headroomArea`
 * (m²) is the floor area with at least `RoomUnderRoof::Headroom` of clear height; both are null for other rooms. An
 * `ancillary` room (a garage, FD-89) is indoor but summed apart from the living area.
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
		public ?int $minClearHeight = null,
		public ?float $headroomArea = null,
		public bool $ancillary = false,
	) {
	}

	public static function fromRoom(RoomDto $room, ?RoomUnderRoof $underRoof = null): self
	{
		return new self(
			id: $room->id,
			code: $room->code,
			name: $room->name,
			type: $room->type,
			area: $room->area,
			perimeter: $room->perimeter,
			clearHeight: $underRoof === null ? $room->ceilingHeight : (int) round($underRoof->maxHeight),
			volume: match (true) {
				$underRoof !== null => round($underRoof->volume / 1e9, 2),
				$room->ceilingHeight === null => null,
				default => round($room->area * $room->ceilingHeight / 1000, 2),
			},
			outdoor: $room->type->isOutdoor(),
			minClearHeight: $underRoof === null ? null : (int) round($underRoof->minHeight),
			headroomArea: $underRoof === null ? null : round($underRoof->headroomArea / 1e6, 2),
			ancillary: $room->type->isAncillary(),
		);
	}
}
