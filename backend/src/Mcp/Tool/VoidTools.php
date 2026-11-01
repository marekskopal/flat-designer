<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Floor voids (FD-90): holes through a floor, open to the floor below (a gallery, a double-height hall). */
final readonly class VoidTools
{
	private const array Clearable = ['liningMaterialId', 'liningDepth'];

	private const string Void = 'A void cuts the floor\'s slab and room floors and the ceilings of the floor below; the walkthrough '
		. 'does not walk into it, and the room schedule takes it off the areas of the rooms around it. Put a railing (add_railing) '
		. 'along its open edges. A stair cuts its own void, as large as its footprint; add a void for a larger well.';

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param list<list<int>> $polygon the void's outline as [[x, y], …] in mm (plan coordinates), at least 3 points, not crossing itself
	 * @param string|null $name e.g. "Gallery void" (default "Void")
	 * @param int|null $liningMaterialId boards lining its edges, from list_materials (default none: the slab's edge)
	 * @param int|null $liningDepth mm the lining reaches down from the floor's level (default: down to the ceilings below)
	 */
	#[McpTool(name: 'add_void', description: 'Add a void: a hole through the floor, open to the floor below. ' . self::Void)]
	public function addVoid(
		int $projectId,
		int $floorId,
		array $polygon,
		?string $name = null,
		?int $liningMaterialId = null,
		?int $liningDepth = null,
	): EditResultDto
	{
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::FloorVoid,
			ToolContext::fields(
				['polygon' => $polygon, 'name' => $name, 'liningMaterialId' => $liningMaterialId, 'liningDepth' => $liningDepth],
			),
		);
	}

	/**
	 * @param list<list<int>>|null $polygon new outline as [[x, y], …] in mm
	 * @param int|null $liningMaterialId boards lining its edges, from list_materials
	 * @param int|null $liningDepth mm the lining reaches down from the floor's level
	 * @param list<string>|null $clear fields to set to none: "liningMaterialId" (unlined), "liningDepth" (down to the ceilings below)
	 */
	#[McpTool(name: 'update_void', description: 'Change a void. Only the given fields change.')]
	public function updateVoid(
		int $projectId,
		int $floorId,
		int $voidId,
		?string $name = null,
		?array $polygon = null,
		?int $liningMaterialId = null,
		?int $liningDepth = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::FloorVoid,
			$voidId,
			ToolContext::fields(
				['name' => $name, 'polygon' => $polygon, 'liningMaterialId' => $liningMaterialId, 'liningDepth' => $liningDepth],
				$clear,
				self::Clearable,
			),
		);
	}

	#[McpTool(name: 'delete_void', description: 'Delete a void (can be undone).', annotations: new ToolAnnotations(destructiveHint: true))]
	public function deleteVoid(int $projectId, int $floorId, int $voidId): EditResultDto
	{
		return $this->editor->delete(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::FloorVoid,
			$voidId,
		);
	}
}
