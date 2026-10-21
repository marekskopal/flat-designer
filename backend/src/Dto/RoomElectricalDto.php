<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** The electrical points of one room, or (`id` null) those of a floor outside every room. */
final readonly class RoomElectricalDto
{
	/** @param list<ElectricalRowDto> $rows */
	public function __construct(
		public ?int $id,
		public ?string $code,
		public string $name,
		public array $rows,
		public ElectricalTotalsDto $totals,
	) {
	}
}
