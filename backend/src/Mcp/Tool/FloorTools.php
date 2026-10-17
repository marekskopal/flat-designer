<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Service\Editor\Catalog\ItemKindCatalog;
use FlatDesigner\Service\Provider\SceneProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class FloorTools
{
	public function __construct(private ToolContext $context, private SceneProvider $sceneProvider, private ItemKindCatalog $catalog,)
	{
	}

	/** @return array<string, mixed> */
	#[McpTool(
		name: 'get_floor',
		description: 'Everything on one floor: rooms (polygon outline, area m², ceiling height, floor material, wall finishes), '
			. 'walls (centre line, thickness, height, type) with their openings (doors/windows: offset from wall start, width, '
			. 'height, sill), and items (kind, footprint centre x/y, elevation z, size, rotation, room, material, params). '
			. 'All in mm. Material ids refer to list_materials.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getFloor(int $projectId, int $floorId): array
	{
		$scene = $this->sceneProvider->getScene($this->context->floor($projectId, $floorId));

		return [
			'floor' => $scene->floor,
			'revision' => $scene->revision,
			'rooms' => $scene->rooms,
			'walls' => $scene->walls,
			'items' => $scene->items,
		];
	}

	/** @return list<array{id: int, code: string, name: string, pattern: string, color: string}> */
	#[McpTool(
		name: 'list_materials',
		description: 'The material catalogue (ids for materialId, floorMaterialId, wall finishes).',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listMaterials(): array
	{
		$materials = [];
		foreach ($this->sceneProvider->getMaterials() as $material) {
			$materials[] = [
				'id' => $material->id,
				'code' => $material->code,
				'name' => $material->name,
				'pattern' => $material->pattern->value,
				'color' => $material->color,
			];
		}

		return $materials;
	}

	/** @return list<array{kind: string, description: string, width: int, depth: int, height: int, z: int, params: string}> */
	#[McpTool(
		name: 'list_item_kinds',
		description: 'Item kinds you can add, each with a description, the default size (width × depth × height) and elevation z '
			. 'a new item gets, and the params it understands.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listItemKinds(): array
	{
		return $this->catalog->all();
	}
}
