<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Stair;

/**
 * A stair as the scene serves it, its fields only: the rise (and so the riser height) comes from the floors, as the
 * scene's `stairRise` (`StairRise`).
 */
final readonly class StairDto
{
	public function __construct(
		public int $id,
		public string $name,
		public string $shape,
		public int $x,
		public int $y,
		public int $rotation,
		public int $width,
		public int $going,
		public ?int $risers,
		public ?int $firstFlight,
		public string $turn,
		public int $gap,
		public string $construction,
		public string $railing,
		public ?int $treadMaterialId,
		public ?int $structureMaterialId,
	) {
	}

	public static function fromEntity(Stair $stair): self
	{
		return new self(
			id: $stair->id,
			name: $stair->name,
			shape: $stair->shape->value,
			x: $stair->x,
			y: $stair->y,
			rotation: $stair->rotation,
			width: $stair->width,
			going: $stair->going,
			risers: $stair->risers,
			firstFlight: $stair->firstFlight,
			turn: $stair->turn->value,
			gap: $stair->gap,
			construction: $stair->construction->value,
			railing: $stair->railing->value,
			treadMaterialId: $stair->treadMaterial?->id,
			structureMaterialId: $stair->structureMaterial?->id,
		);
	}
}
