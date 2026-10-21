<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** The surfaces of one room and their cost: `complete` is false when a line has no price (its cost is left out). */
final readonly class QuantityRoomDto
{
	/** @param list<QuantityLineDto> $lines */
	public function __construct(
		public int $id,
		public string $code,
		public string $name,
		public int $floorId,
		public string $floorName,
		public array $lines,
		public float $cost,
		public bool $complete,
	) {
	}
}
