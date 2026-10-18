<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Identical parts that are not boards (legs, rods, glass, LEDs): their box `w` × `h` × `d` in mm. */
final readonly class CutListOtherDto
{
	/**
	 * @param list<string> $parts
	 * @param list<string> $items
	 */
	public function __construct(
		public string $type,
		public ?int $materialId,
		public string $material,
		public int $w,
		public int $h,
		public int $d,
		public int $count,
		public array $parts,
		public array $items,
	) {
	}
}
