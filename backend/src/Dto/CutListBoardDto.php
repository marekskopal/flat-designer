<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/**
 * Identical boards: `length` runs along the grain, `width` across it, `thickness` the smallest side; `edges` are the
 * banded sides (`left`, `right`, `bottom`, `top`, `back`, `front` of the part's box). `parts` and `items` name where they go.
 */
final readonly class CutListBoardDto
{
	/**
	 * @param list<string> $edges
	 * @param list<string> $parts
	 * @param list<string> $items
	 */
	public function __construct(
		public ?int $materialId,
		public string $material,
		public int $thickness,
		public int $length,
		public int $width,
		public int $count,
		public array $edges,
		public array $parts,
		public array $items,
	) {
	}
}
