<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/**
 * What a carpenter cuts for the Custom items of a project or floor (only the shown variants): `boards` (board and front
 * parts grouped by material, thickness, size and banded edges), `others` (legs, rods, glass, LEDs), `materials` (board
 * area and edge banding per material) and `skipped` items whose definition does not work out. Lengths in mm, the
 * totals in m² and m.
 */
final readonly class CutListDto
{
	/**
	 * @param list<CutListBoardDto> $boards
	 * @param list<CutListOtherDto> $others
	 * @param list<CutListMaterialDto> $materials
	 * @param list<array{itemId: int, itemName: string, floorName: string, error: string}> $skipped
	 */
	public function __construct(public array $boards, public array $others, public array $materials, public array $skipped)
	{
	}
}
