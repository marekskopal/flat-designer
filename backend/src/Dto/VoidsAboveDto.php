<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/**
 * The floor above (FD-90), the one this floor's stairs climb to: `rise` mm up to its level, its slab, and its voids,
 * which cut this floor's ceilings; the viewer lines the well between the ceilings and the slab.
 */
final readonly class VoidsAboveDto
{
	/** @param list<FloorVoidDto> $voids */
	public function __construct(public int $floorId, public int $rise, public int $slabThickness, public array $voids)
	{
	}
}
