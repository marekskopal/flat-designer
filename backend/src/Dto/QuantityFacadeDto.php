<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/**
 * A floor's façades (FD-88): the wall faces outside every room per material (m², without the openings) and their cost;
 * `complete` is false when a line has no price.
 */
final readonly class QuantityFacadeDto
{
	/** @param list<QuantityLineDto> $lines */
	public function __construct(
		public int $floorId,
		public string $floorName,
		public array $lines,
		public float $cost,
		public bool $complete,
	) {
	}
}
