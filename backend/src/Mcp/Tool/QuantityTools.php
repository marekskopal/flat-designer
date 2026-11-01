<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\MaterialPriceDto;
use FlatDesigner\Dto\MaterialPricesDto;
use FlatDesigner\Dto\QuantitiesDto;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Service\Quantities\MaterialPriceService;
use FlatDesigner\Service\Quantities\QuantitiesService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Quantities and cost estimate: flooring, wall finishes, paint, ceilings and skirting per room and material, furniture
 * boards, priced from the project's price list. Prices are not floor elements: no revision, no undo.
 */
final readonly class QuantityTools
{
	/** The surfaces a default look can be priced for; QuantityToolsTest checks it is every `QuantitySurfaceEnum` case. */
	public const array Surfaces = [
		QuantitySurfaceEnum::Floor->value,
		QuantitySurfaceEnum::WallFinish->value,
		QuantitySurfaceEnum::Wall->value,
		QuantitySurfaceEnum::Ceiling->value,
		QuantitySurfaceEnum::Beams->value,
		QuantitySurfaceEnum::Skirting->value,
		QuantitySurfaceEnum::Furniture->value,
		QuantitySurfaceEnum::RoofCovering->value,
		QuantitySurfaceEnum::Soffit->value,
		QuantitySurfaceEnum::Facade->value,
		QuantitySurfaceEnum::Gutter->value,
		QuantitySurfaceEnum::Downpipe->value,
	];

	public const array PricesSchema = [
		'type' => 'array',
		'description' => 'prices to set: {materialId, pricePerArea?, pricePerLength?} for a material (list_materials), or '
			. '{surface, pricePerArea?, pricePerLength?} for the default look of a surface without a material (e.g. a ceiling '
			. 'without a material is white plaster). '
			. 'pricePerArea is per m², pricePerLength per running metre (skirting, gutters, downpipes, edge banding of furniture boards). Both null '
			. 'removes the price.',
		'items' => [
			'type' => 'object',
			'properties' => [
				'materialId' => ['type' => ['integer', 'null']],
				'surface' => ['type' => ['string', 'null'], 'enum' => [...self::Surfaces, null]],
				'pricePerArea' => ['type' => ['number', 'null']],
				'pricePerLength' => ['type' => ['number', 'null']],
			],
		],
	];

	public function __construct(
		private ToolContext $context,
		private QuantitiesService $quantitiesService,
		private MaterialPriceService $priceService,
	) {
	}

	/** @param int|null $floorId one floor only (default: the whole project) */
	#[McpTool(
		name: 'get_quantities',
		description: 'Quantities and cost estimate of a project or floor: per room the floor (m²), wall finish bands (m²), '
			. 'wall paint around and above them up to the ceiling without openings (m²), ceiling and vault beams (m²) and '
			. 'skirting (running m), each with its material, tile or plank count (waste included) and cost; the totals to buy '
			. 'per material; the furniture boards per material (from the cut list); per roof its covering and soffit (m², '
			. 'along the slope, without its windows) and its number of roof windows; the total in the project\'s currency. '
			. 'Under a roof the ceiling counts along the slope and the walls are painted up to it. '
			. 'cost null means the material has no price yet (set_material_prices); complete is false then.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getQuantities(int $projectId, ?int $floorId = null): QuantitiesDto
	{
		return $floorId === null
			? $this->quantitiesService->forProject($this->context->project($projectId))
			: $this->quantitiesService->forFloor($this->context->floor($projectId, $floorId));
	}

	/**
	 * @param list<array<string, mixed>> $prices the prices to set (see the schema); the others stay
	 * @param string|null $currency three-letter code of all prices, e.g. EUR or CZK (default: unchanged)
	 * @param int|null $wastePercent allowance for cuts and breakage added to tiles and planks, 0–100 % (default: unchanged)
	 */
	#[McpTool(
		name: 'set_material_prices',
		description: 'Set a project\'s prices of materials for the cost estimate (get_quantities), its currency and waste '
			. 'allowance. Prices not given stay; returns the whole price list.',
	)]
	public function setMaterialPrices(
		int $projectId,
		#[Schema(definition: self::PricesSchema)]
		array $prices = [],
		?string $currency = null,
		?int $wastePercent = null,
	): MaterialPricesDto {
		return $this->priceService->merge(
			$this->context->project($projectId),
			array_map(MaterialPriceDto::fromArray(...), $prices),
			$currency,
			$wastePercent,
		);
	}
}
