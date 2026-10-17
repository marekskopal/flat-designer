<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\MaterialPatternEnum;
use FlatDesigner\Model\Entity\Material;

final readonly class MaterialDto
{
	public function __construct(
		public int $id,
		public string $code,
		public string $name,
		public MaterialPatternEnum $pattern,
		public string $color,
		public float $roughness,
		public float $metalness,
	) {
	}

	public static function fromEntity(Material $material): self
	{
		return new self(
			id: $material->id,
			code: $material->code,
			name: $material->name,
			pattern: $material->pattern,
			color: $material->color,
			roughness: $material->roughness,
			metalness: $material->metalness,
		);
	}
}
