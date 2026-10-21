<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;

/**
 * What to buy of one material: `area` (m²) and `length` (running m: skirting, or edge banding of furniture boards),
 * `pieces` for tiles and planks (whole pieces for all rooms together, the waste allowance included) and the
 * `orderedArea` they make, the project's prices and the `cost` (null without the price it needs). Without a material,
 * `surface` says whose default look it is.
 */
final readonly class QuantityMaterialDto
{
	/** @param list<QuantitySurfaceEnum> $surfaces where the material is used */
	public function __construct(
		public ?int $materialId,
		public ?QuantitySurfaceEnum $surface,
		public string $material,
		public array $surfaces,
		public ?float $area,
		public ?float $length,
		public ?int $pieces,
		public ?float $orderedArea,
		public ?float $pricePerArea,
		public ?float $pricePerLength,
		public ?float $cost,
	) {
	}
}
