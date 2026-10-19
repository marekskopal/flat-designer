<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\MaterialPatternEnum;

/** A material as added or changed by an admin (`MaterialService`); the texture is not set this way. */
final readonly class MaterialInputDto
{
	public function __construct(
		public string $code,
		public string $name,
		public MaterialPatternEnum $pattern,
		public string $color,
		public float $roughness,
		public float $metalness,
		public ?int $tileWidth,
		public ?int $tileHeight,
	) {
	}
}
