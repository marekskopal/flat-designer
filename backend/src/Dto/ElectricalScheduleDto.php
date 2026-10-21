<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** The electrical schedule of a project or one floor (working copy, shown variants): points per room and the totals. */
final readonly class ElectricalScheduleDto
{
	/** @param list<FloorElectricalDto> $floors */
	public function __construct(public array $floors, public ElectricalTotalsDto $totals)
	{
	}
}
