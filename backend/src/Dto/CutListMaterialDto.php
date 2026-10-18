<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Totals of one board material: `area` of all its boards (m², one face) and `edgeLength` of their banded edges (m). */
final readonly class CutListMaterialDto
{
	public function __construct(public ?int $materialId, public string $material, public float $area, public float $edgeLength)
	{
	}
}
