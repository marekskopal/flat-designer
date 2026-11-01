<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use FlatDesigner\Dto\CutListMaterialDto;
use FlatDesigner\Dto\MaterialDto;
use FlatDesigner\Dto\QuantityLineDto;
use FlatDesigner\Dto\QuantityMaterialDto;
use FlatDesigner\Model\Entity\Enum\MaterialPatternEnum;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;

/**
 * Turns surfaces into quantities and costs with a project's prices. Materials with a tile size and a pattern laid in
 * pieces are counted in whole pieces, the waste allowance included, and cost what those pieces cover. Skirting is
 * priced per running metre, everything else per m²; furniture boards add their edge banding when the board material
 * has a price per metre.
 */
final readonly class Costing
{
	/** Patterns laid in pieces of `tileWidth` × `tileHeight`. */
	private const array PiecePatterns = [
		MaterialPatternEnum::Tiles,
		MaterialPatternEnum::Planks,
		MaterialPatternEnum::Herringbone,
		MaterialPatternEnum::Terrazzo,
		MaterialPatternEnum::Brick,
		MaterialPatternEnum::BeaverTail,
	];

	/** The share of a beaver-tail tile's length a row shows (double-lap tiling; `materials.ts` draws the rows so). */
	public const float BeaverTailGauge = 0.4;

	/** @param array<int, MaterialDto> $materials by id */
	public function __construct(private PriceList $prices, private array $materials, private float $waste)
	{
	}

	public function material(?int $materialId): ?MaterialDto
	{
		return $this->materials[$materialId ?? 0] ?? null;
	}

	/** @param array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float} $surface from RoomSurfaces */
	public function line(array $surface): QuantityLineDto
	{
		$material = $this->material($surface['materialId']);
		$byLength = $surface['surface']->byLength();
		$area = $byLength ? null : $surface['amount'] / 1000000;
		$length = $byLength ? $surface['amount'] / 1000 : null;
		$pieces = $this->pieces($material, $area);
		$price = $this->prices->find($material?->id, $surface['surface']);
		$cost = $byLength
			? self::times($length, $price?->pricePerLength)
			: self::times(self::ordered($material, $area ?? 0.0, $pieces), $price?->pricePerArea);

		return new QuantityLineDto(
			surface: $surface['surface'],
			materialId: $material?->id,
			material: $material->name ?? $surface['surface']->defaultMaterialName(),
			area: self::round($area),
			length: self::round($length),
			pieces: $pieces,
			cost: self::round($cost),
		);
	}

	/**
	 * What to buy of a material over all rooms.
	 *
	 * @param list<QuantitySurfaceEnum> $surfaces
	 */
	public function total(
		?MaterialDto $material,
		QuantitySurfaceEnum $surface,
		array $surfaces,
		float $area,
		float $length,
	): QuantityMaterialDto
	{
		$price = $this->prices->find($material?->id, $surface);
		$pieces = $area > 0 ? $this->pieces($material, $area) : null;
		$areaCost = $area > 0 ? self::times(self::ordered($material, $area, $pieces), $price?->pricePerArea) : 0.0;
		$lengthCost = $length > 0 ? self::times($length, $price?->pricePerLength) : 0.0;

		return new QuantityMaterialDto(
			materialId: $material?->id,
			surface: $material === null ? $surface : null,
			material: $material->name ?? $surface->defaultMaterialName(),
			surfaces: $surfaces,
			area: $area > 0 ? round($area, 2) : null,
			length: $length > 0 ? round($length, 2) : null,
			pieces: $pieces,
			orderedArea: $pieces === null ? null : round(self::ordered($material, $area, $pieces), 2),
			pricePerArea: $price?->pricePerArea,
			pricePerLength: $price?->pricePerLength,
			cost: $areaCost === null || $lengthCost === null ? null : round($areaCost + $lengthCost, 2),
		);
	}

	/** The boards of one material of the cut list. */
	public function boards(CutListMaterialDto $boards): QuantityMaterialDto
	{
		$material = $this->material($boards->materialId);
		$price = $this->prices->find($material?->id, QuantitySurfaceEnum::Furniture);
		$cost = self::times($boards->area, $price?->pricePerArea);

		return new QuantityMaterialDto(
			materialId: $material?->id,
			surface: $material === null ? QuantitySurfaceEnum::Furniture : null,
			material: $boards->material,
			surfaces: [QuantitySurfaceEnum::Furniture],
			area: $boards->area,
			length: $boards->edgeLength > 0 ? $boards->edgeLength : null,
			pieces: null,
			orderedArea: null,
			pricePerArea: $price?->pricePerArea,
			pricePerLength: $price?->pricePerLength,
			cost: $cost === null ? null : round($cost + $boards->edgeLength * ($price->pricePerLength ?? 0.0), 2),
		);
	}

	/** Whole pieces of a material laid in pieces, waste included; null for the others. */
	private function pieces(?MaterialDto $material, ?float $area): ?int
	{
		$piece = self::pieceArea($material);
		if ($piece === null || $area === null) {
			return null;
		}

		// A hair below a whole piece is that piece, not one more.
		return (int) ceil($area * (1 + $this->waste) / $piece - 1e-6);
	}

	/** The area bought: whole pieces, or the area itself. */
	private static function ordered(?MaterialDto $material, float $area, ?int $pieces): float
	{
		$piece = self::pieceArea($material);

		return $piece === null || $pieces === null ? $area : $pieces * $piece;
	}

	/** m² of one piece, or null when the material is not laid in pieces. */
	private static function pieceArea(?MaterialDto $material): ?float
	{
		if (
			$material?->tileWidth === null
			|| $material->tileHeight === null
			|| !in_array($material->pattern, self::PiecePatterns, true)
		) {
			return null;
		}

		$length = $material->pattern === MaterialPatternEnum::BeaverTail
			? $material->tileHeight * self::BeaverTailGauge
			: $material->tileHeight;

		return $material->tileWidth * $length / 1000000;
	}

	private static function times(?float $amount, ?float $price): ?float
	{
		return $amount === null || $price === null ? null : $amount * $price;
	}

	private static function round(?float $value): ?float
	{
		return $value === null ? null : round($value, 2);
	}
}
