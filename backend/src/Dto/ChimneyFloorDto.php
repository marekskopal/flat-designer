<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/**
 * Another floor of a project with chimneys (FD-91), as a floor's scene sees it: `offset` mm from this floor's level up to
 * its own (negative below), its roofs (without windows), which the chimneys' tops reach over, and its chimneys, the
 * stretches of those below rising through this floor being drawn here.
 */
final readonly class ChimneyFloorDto
{
	/**
	 * @param list<RoofDto> $roofs
	 * @param list<ItemDto> $chimneys
	 */
	public function __construct(public int $floorId, public int $offset, public array $roofs, public array $chimneys)
	{
	}
}
