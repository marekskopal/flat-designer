<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\MaterialInputDto;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Service\Material\MaterialException;
use FlatDesigner\Service\Material\MaterialService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/** The global material catalogue: everyone reads it, admins add and change materials. */
final readonly class MaterialTools
{
	private const array Patterns = ['Plain', 'Wood', 'Planks', 'Tiles', 'Glass', 'Grass', 'Gravel'];

	private const array Clearable = ['tileWidth', 'tileHeight'];

	public function __construct(
		private ToolContext $context,
		private MaterialRepository $materialRepository,
		private MaterialService $materialService,
	) {
	}

	/** @return list<array<string, mixed>> */
	#[McpTool(
		name: 'list_materials',
		description: 'The material catalogue (ids for materialId, floorMaterialId, wall finishes and Custom params.materials; '
			. 'codes for material slot defaults of furniture definitions). textured: drawn from a photo; tileWidth × tileHeight: '
			. 'the real size of one plank or tile in mm.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listMaterials(): array
	{
		return array_map(self::toArray(...), $this->materialRepository->findAllOrdered());
	}

	/**
	 * @param string $code unique kebab-case code, permanent (furniture definitions refer to it), e.g. "oak-veneer"
	 * @param string $name e.g. "Oak veneer, natural"
	 * @param string $pattern how the surface is drawn: Plain, Wood (grain), Planks, Tiles, Glass, Grass, Gravel
	 * @param string $color base colour #rrggbb
	 * @param float $roughness 0 (mirror) to 1 (matt)
	 * @param float $metalness 0 (dielectric) to 1 (metal)
	 * @param int|null $tileWidth Planks/Tiles: one plank or tile, mm (with tileHeight)
	 * @param int|null $tileHeight Planks/Tiles: one plank or tile, mm (with tileWidth)
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'add_material',
		description: 'Admins only: add a material to the global catalogue shared by all projects. Check list_materials first '
			. 'for one that fits. Textures (photos) cannot be added this way.',
	)]
	public function addMaterial(
		string $code,
		string $name,
		#[Schema(enum: self::Patterns)]
		string $pattern,
		string $color,
		float $roughness = 0.6,
		float $metalness = 0.0,
		?int $tileWidth = null,
		?int $tileHeight = null,
	): array {
		return self::toArray($this->materialService->create($this->context->user(), new MaterialInputDto(
			code: $code,
			name: $name,
			pattern: MaterialService::pattern($pattern),
			color: $color,
			roughness: $roughness,
			metalness: $metalness,
			tileWidth: $tileWidth,
			tileHeight: $tileHeight,
		)));
	}

	/**
	 * @param string|null $name new name
	 * @param string|null $pattern Plain, Wood, Planks, Tiles, Glass, Grass or Gravel
	 * @param string|null $color #rrggbb
	 * @param float|null $roughness 0–1
	 * @param float|null $metalness 0–1
	 * @param int|null $tileWidth mm (with tileHeight)
	 * @param int|null $tileHeight mm (with tileWidth)
	 * @param list<string>|null $clear fields to set to none: "tileWidth", "tileHeight" (both together)
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'update_material',
		description: 'Admins only: change a material; only the given fields change. The code stays. Everything using it '
			. 'changes with it, in all projects and saved versions.',
	)]
	public function updateMaterial(
		int $materialId,
		?string $name = null,
		#[Schema(enum: self::Patterns)]
		?string $pattern = null,
		?string $color = null,
		?float $roughness = null,
		?float $metalness = null,
		?int $tileWidth = null,
		?int $tileHeight = null,
		?array $clear = null,
	): array {
		$material = $this->materialRepository->findById($materialId)
			?? throw MaterialException::notFound(sprintf('Material %d not found. Use list_materials to see the catalogue.', $materialId));
		foreach ($clear ?? [] as $field) {
			if (!in_array($field, self::Clearable, true)) {
				throw new MaterialException(
					sprintf('"%s" cannot be cleared; clearable fields: %s.', $field, implode(', ', self::Clearable)),
				);
			}
		}
		$cleared = static fn (string $field): bool => in_array($field, $clear ?? [], true);

		return self::toArray($this->materialService->update($this->context->user(), $material, new MaterialInputDto(
			code: $material->code,
			name: $name ?? $material->name,
			pattern: $pattern === null ? $material->pattern : MaterialService::pattern($pattern),
			color: $color ?? $material->color,
			roughness: $roughness ?? $material->roughness,
			metalness: $metalness ?? $material->metalness,
			tileWidth: $cleared('tileWidth') ? null : $tileWidth ?? $material->tileWidth,
			tileHeight: $cleared('tileHeight') ? null : $tileHeight ?? $material->tileHeight,
		)));
	}

	/** @return array<string, mixed> */
	private static function toArray(Material $material): array
	{
		return [
			'id' => $material->id,
			'code' => $material->code,
			'name' => $material->name,
			'pattern' => $material->pattern->value,
			'color' => $material->color,
			'roughness' => $material->roughness,
			'metalness' => $material->metalness,
			'textured' => $material->texture !== null,
			'tileWidth' => $material->tileWidth,
			'tileHeight' => $material->tileHeight,
		];
	}
}
