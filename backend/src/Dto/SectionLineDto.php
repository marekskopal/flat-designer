<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\SectionLine;

/**
 * A section line as the scene serves it (FD-94): from start to end in plan mm, looking to the left of start → end,
 * `depth` mm deep (null: all beyond).
 */
final readonly class SectionLineDto
{
	public function __construct(
		public int $id,
		public string $name,
		public int $startX,
		public int $startY,
		public int $endX,
		public int $endY,
		public ?int $depth,
	) {
	}

	public static function fromEntity(SectionLine $line): self
	{
		return new self(
			id: $line->id,
			name: $line->name,
			startX: $line->startX,
			startY: $line->startY,
			endX: $line->endX,
			endY: $line->endY,
			depth: $line->depth,
		);
	}
}
