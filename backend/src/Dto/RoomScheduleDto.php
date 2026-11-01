<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** The room schedule of a project or one floor (working copy): the floors' rooms and the indoor, ancillary and outdoor totals. */
final readonly class RoomScheduleDto
{
	/** @param list<FloorScheduleDto> $floors */
	public function __construct(
		public array $floors,
		public ScheduleTotalDto $indoor,
		public ScheduleTotalDto $outdoor,
		public ScheduleTotalDto $ancillary,
	)
	{
	}
}
