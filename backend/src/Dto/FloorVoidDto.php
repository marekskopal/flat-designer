<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\FloorVoid;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Json\JsonDecoder;

/**
 * A floor void as the scene serves it (FD-90): its outline (plan mm), area in m², the lining's material (null: none)
 * and depth (mm down from the floor's level; null: down to the ceilings below).
 */
final readonly class FloorVoidDto
{
	/** @param list<array{int, int}> $polygon */
	public function __construct(
		public int $id,
		public string $name,
		public array $polygon,
		public float $area,
		public ?int $liningMaterialId,
		public ?int $liningDepth,
	) {
	}

	public static function fromEntity(FloorVoid $void): self
	{
		$polygon = JsonDecoder::decodePolygon($void->polygon);
		$points = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $polygon);

		return new self(
			id: $void->id,
			name: $void->name,
			polygon: $polygon,
			area: round(abs(PlanMath::signedArea($points)) / 1000000, 2),
			liningMaterialId: $void->liningMaterial?->id,
			liningDepth: $void->liningDepth,
		);
	}
}
