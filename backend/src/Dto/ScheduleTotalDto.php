<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Sum over rooms of the schedule: count, net area (m²) and perimeter (m). */
final readonly class ScheduleTotalDto
{
	public function __construct(public int $rooms, public float $area, public float $perimeter)
	{
	}

	/** @param list<RoomScheduleRowDto> $rows */
	public static function of(array $rows): self
	{
		return new self(
			rooms: count($rows),
			area: round(array_sum(array_map(static fn (RoomScheduleRowDto $r): float => $r->area, $rows)), 2),
			perimeter: round(array_sum(array_map(static fn (RoomScheduleRowDto $r): float => $r->perimeter, $rows)), 2),
		);
	}
}
