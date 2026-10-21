<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use FlatDesigner\Dto\MaterialPriceDto;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;

/** A project's prices by material, and by surface for the surfaces' default looks. */
final readonly class PriceList
{
	/**
	 * @param array<int, MaterialPriceDto> $byMaterial
	 * @param array<string, MaterialPriceDto> $bySurface
	 */
	private function __construct(private array $byMaterial, private array $bySurface)
	{
	}

	/** @param list<MaterialPriceDto> $prices */
	public static function of(array $prices): self
	{
		$byMaterial = [];
		$bySurface = [];
		foreach ($prices as $price) {
			if ($price->materialId !== null) {
				$byMaterial[$price->materialId] = $price;
			} elseif ($price->surface !== null) {
				$bySurface[$price->surface->value] = $price;
			}
		}

		return new self($byMaterial, $bySurface);
	}

	/** What a price (or a quantity total) is for: a material, or a surface's default look. Twin of `priceKey()` in the page. */
	public static function key(?int $materialId, ?QuantitySurfaceEnum $surface): string
	{
		return $materialId === null ? 'surface:' . ($surface->value ?? '') : 'material:' . $materialId;
	}

	/** The price of a material wherever it is used, or of the surface's default look when there is no material. */
	public function find(?int $materialId, QuantitySurfaceEnum $surface): ?MaterialPriceDto
	{
		return $materialId === null ? $this->bySurface[$surface->value] ?? null : $this->byMaterial[$materialId] ?? null;
	}
}
