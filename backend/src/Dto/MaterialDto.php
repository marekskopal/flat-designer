<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\MaterialPatternEnum;
use Izbik\Model\Entity\Material;

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
		public ?string $texture,
		public ?int $tileWidth,
		public ?int $tileHeight,
		public ?string $accent = null,
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
			texture: $material->texture,
			tileWidth: $material->tileWidth,
			tileHeight: $material->tileHeight,
			accent: $material->accent,
		);
	}
}
