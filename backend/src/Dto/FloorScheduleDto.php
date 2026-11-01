<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/**
 * The rooms of one floor with the indoor (living), ancillary (garage) and outdoor (terrace, garden) totals, and its voids
 * (FD-90), which the rooms' areas leave out.
 */
final readonly class FloorScheduleDto
{
	/**
	 * @param list<RoomScheduleRowDto> $rooms
	 * @param list<VoidScheduleRowDto> $voids
	 */
	public function __construct(
		public int $id,
		public string $name,
		public int $level,
		public int $elevation,
		public array $rooms,
		public ScheduleTotalDto $indoor,
		public ScheduleTotalDto $outdoor,
		public ScheduleTotalDto $ancillary,
		public array $voids = [],
	) {
	}
}
