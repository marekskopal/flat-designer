<?php

declare(strict_types=1);

namespace Izbik\Dto;

/**
 * A roof's quantities: its covering and soffit (m², along the slope, without its windows and where another roof
 * is higher), the number of its windows and the cost; `complete` is false when a line has no price.
 */
final readonly class QuantityRoofDto
{
	/** @param list<QuantityLineDto> $lines */
	public function __construct(
		public int $id,
		public string $name,
		public int $floorId,
		public string $floorName,
		public array $lines,
		public int $windows,
		public float $cost,
		public bool $complete,
	) {
	}
}
