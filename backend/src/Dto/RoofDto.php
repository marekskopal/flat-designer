<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Roof;

/** A roof as the scene serves it, with its windows (elements of their own, as a wall's openings are). */
final readonly class RoofDto
{
	/** @param list<RoofWindowDto> $windows */
	public function __construct(
		public int $id,
		public string $name,
		public string $shape,
		public int $x,
		public int $y,
		public int $rotation,
		public int $width,
		public int $depth,
		public int $eaveHeight,
		public int $pitch,
		public int $overhang,
		public int $vergeOverhang,
		public int $thickness,
		public ?int $parapet,
		public ?int $coveringMaterialId,
		public ?int $soffitMaterialId,
		public array $windows,
	) {
	}

	/** @param list<RoofWindowDto> $windows */
	public static function fromEntity(Roof $roof, array $windows): self
	{
		return new self(
			id: $roof->id,
			name: $roof->name,
			shape: $roof->shape->value,
			x: $roof->x,
			y: $roof->y,
			rotation: $roof->rotation,
			width: $roof->width,
			depth: $roof->depth,
			eaveHeight: $roof->eaveHeight,
			pitch: $roof->pitch,
			overhang: $roof->overhang,
			vergeOverhang: $roof->vergeOverhang,
			thickness: $roof->thickness,
			parapet: $roof->parapet,
			coveringMaterialId: $roof->coveringMaterial?->id,
			soffitMaterialId: $roof->soffitMaterial?->id,
			windows: $windows,
		);
	}
}
