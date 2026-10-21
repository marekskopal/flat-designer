<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** The rooms of one floor with their electrical points; a last room with `id` null holds the points outside every room. */
final readonly class FloorElectricalDto
{
	/** @param list<RoomElectricalDto> $rooms */
	public function __construct(
		public int $id,
		public string $name,
		public int $level,
		public array $rooms,
		public ElectricalTotalsDto $totals,
	) {
	}
}
