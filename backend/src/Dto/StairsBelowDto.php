<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/**
 * The stairs of the floor below that come up through this one, with their rise: they cut their voids through this
 * floor, and the viewer draws them in the voids.
 */
final readonly class StairsBelowDto
{
	/** @param list<StairDto> $stairs */
	public function __construct(public int $floorId, public int $rise, public array $stairs)
	{
	}
}
