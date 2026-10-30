<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Railing;
use FlatDesigner\Service\Json\JsonDecoder;

/**
 * A railing as the scene serves it (FD-90): its polyline (plan mm), height over its base (`baseZ`, mm against the
 * floor's level), infill style with its spacing (null: the style's), the posts' spacing, the handrail and materials.
 */
final readonly class RailingDto
{
	/** @param list<array{int, int}> $points */
	public function __construct(
		public int $id,
		public string $name,
		public array $points,
		public int $height,
		public int $baseZ,
		public string $style,
		public ?int $spacing,
		public int $postSpacing,
		public string $handrail,
		public ?int $materialId,
		public ?int $infillMaterialId,
	) {
	}

	public static function fromEntity(Railing $railing): self
	{
		return new self(
			id: $railing->id,
			name: $railing->name,
			points: JsonDecoder::decodePolygon($railing->points),
			height: $railing->height,
			baseZ: $railing->baseZ,
			style: $railing->style->value,
			spacing: $railing->spacing,
			postSpacing: $railing->postSpacing,
			handrail: $railing->handrail->value,
			materialId: $railing->material?->id,
			infillMaterialId: $railing->infillMaterial?->id,
		);
	}
}
