<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Plot;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Json\JsonDecoder;

/** A plot as the scene serves it: its boundary (plan mm), area in m², ground material (null: a lawn) and level (mm). */
final readonly class PlotDto
{
	/** @param list<array{int, int}> $polygon */
	public function __construct(
		public int $id,
		public string $name,
		public array $polygon,
		public float $area,
		public ?int $groundMaterialId,
		public int $groundLevel,
	)
	{
	}

	public static function fromEntity(Plot $plot): self
	{
		$polygon = JsonDecoder::decodePolygon($plot->polygon);
		$points = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $polygon);

		return new self(
			id: $plot->id,
			name: $plot->name,
			polygon: $polygon,
			area: round(abs(PlanMath::signedArea($points)) / 1000000, 2),
			groundMaterialId: $plot->groundMaterial?->id,
			groundLevel: $plot->groundLevel,
		);
	}
}
