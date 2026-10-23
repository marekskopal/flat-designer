<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** The rooms of one floor with the indoor and outdoor (terrace, garden) totals. */
final readonly class FloorScheduleDto
{
	/** @param list<RoomScheduleRowDto> $rooms */
	public function __construct(
		public int $id,
		public string $name,
		public int $level,
		public int $elevation,
		public array $rooms,
		public ScheduleTotalDto $indoor,
		public ScheduleTotalDto $outdoor,
	) {
	}
}
