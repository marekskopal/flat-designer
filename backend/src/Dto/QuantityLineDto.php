<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;

/**
 * One material on one surface of a room: `area` (m²) or, for skirting, `length` (running m); `pieces` of a tiled or
 * planked material with a tile size, the waste allowance included; `cost` in the project's currency, null without a
 * price. `materialId` null is the surface's default look (`material` names it).
 */
final readonly class QuantityLineDto
{
	public function __construct(
		public QuantitySurfaceEnum $surface,
		public ?int $materialId,
		public string $material,
		public ?float $area,
		public ?float $length,
		public ?int $pieces,
		public ?float $cost,
	) {
	}
}
