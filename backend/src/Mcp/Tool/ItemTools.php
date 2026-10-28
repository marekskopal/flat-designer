<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/** Items: cabinets, appliances, sanitary ware, fences, … (see list_item_kinds). */
final readonly class ItemTools
{
	private const array Clearable = ['roomId', 'groupId', 'materialId', 'definitionId', 'modelId'];

	/** A JSON object (PHP arrays would otherwise be advertised as JSON arrays). */
	private const array ParamsSchema = [
		'type' => ['object', 'null'],
		'description' => 'kind-specific options as a JSON object, see list_item_kinds (update_item replaces all params); '
			. 'the listed properties are those of Custom',
		'properties' => [
			'values' => [
				'type' => 'object',
				'additionalProperties' => true,
				'description' => 'Custom: parameter values over the definition\'s defaults, e.g. {"shelves": 6}',
			],
			'materials' => [
				'type' => 'object',
				'additionalProperties' => ['type' => 'integer'],
				'description' => 'Custom: material id (list_materials) per material slot key of the definition',
			],
		],
		'additionalProperties' => true,
		'default' => null,
	];

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param string $kind item kind from list_item_kinds, e.g. "Custom"
	 * @param int $x footprint centre, mm east of the floor origin
	 * @param int $y footprint centre, mm north of the floor origin
	 * @param string|null $name label shown to the user (defaults to the kind)
	 * @param int|null $width mm along the front (default from list_item_kinds)
	 * @param int|null $depth mm front to back
	 * @param int|null $height mm
	 * @param int|null $z bottom elevation above the floor, mm (with onGround: above the ground under the item's centre)
	 * @param bool|null $onGround z counts from the sloping ground of the plot under the item (trees, a shed, garden furniture) (default false)
	 * @param int|null $rotation degrees counter-clockwise; 0 = front facing south, 90 east, 180 north, 270 west
	 * @param int|null $roomId room the item belongs to (from get_floor)
	 * @param int|null $groupId group (piece of furniture) the item is part of (from get_floor)
	 * @param int|null $materialId body/front material (from list_materials)
	 * @param array<string, mixed>|null $params kind-specific options (see list_item_kinds)
	 * @param int|null $definitionId for kind "Custom": the furniture definition (list_furniture_definitions); the size and name
	 *     default to the definition's
	 * @param int|null $modelId for kind "Model": the 3D model of a bought product (list_models); the size and name default to
	 *     the model's
	 */
	#[McpTool(name: 'add_item', description: 'Add an item to a floor. Unspecified size and elevation come from the kind\'s defaults.')]
	public function addItem(
		int $projectId,
		int $floorId,
		string $kind,
		int $x,
		int $y,
		?string $name = null,
		?int $width = null,
		?int $depth = null,
		?int $height = null,
		?int $z = null,
		?int $rotation = null,
		?int $roomId = null,
		?int $groupId = null,
		?int $materialId = null,
		#[Schema(definition: self::ParamsSchema)]
		?array $params = null,
		?int $definitionId = null,
		?int $modelId = null,
		?bool $onGround = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Item,
			ToolContext::fields([
				'kind' => $kind,
				'x' => $x,
				'y' => $y,
				'name' => $name,
				'width' => $width,
				'depth' => $depth,
				'height' => $height,
				'z' => $z,
				'rotation' => $rotation,
				'roomId' => $roomId,
				'groupId' => $groupId,
				'materialId' => $materialId,
				'params' => $params,
				'definitionId' => $definitionId,
				'modelId' => $modelId,
				'onGround' => $onGround,
			]),
		);
	}

	/**
	 * @param string|null $kind item kind from list_item_kinds
	 * @param int|null $x footprint centre, mm east
	 * @param int|null $y footprint centre, mm north
	 * @param int|null $z bottom elevation, mm
	 * @param bool|null $onGround z counts from the ground of the plot under the item instead of the floor's level
	 * @param int|null $rotation degrees counter-clockwise, 0 = front facing south
	 * @param int|null $groupId group to move the item into (from get_floor)
	 * @param array<string, mixed>|null $params replaces all params of the item
	 * @param int|null $definitionId another furniture definition of a Custom item (send params {} with it, the old values
	 *     belong to the old one)
	 * @param int|null $modelId another 3D model of a Model item (list_models)
	 * @param list<string>|null $clear fields to set to none: "roomId", "groupId" (take it out of its group), "materialId",
	 *     "definitionId" (with a kind other than Custom), "modelId" (with a kind other than Model)
	 */
	#[McpTool(
		name: 'update_item',
		description: 'Change an item. Only the given fields change; use clear to unset roomId, groupId, materialId, definitionId or modelId.',
	)]
	public function updateItem(
		int $projectId,
		int $floorId,
		int $itemId,
		?string $kind = null,
		?string $name = null,
		?int $x = null,
		?int $y = null,
		?int $z = null,
		?int $width = null,
		?int $depth = null,
		?int $height = null,
		?int $rotation = null,
		?int $roomId = null,
		?int $groupId = null,
		?int $materialId = null,
		#[Schema(definition: self::ParamsSchema)]
		?array $params = null,
		?int $definitionId = null,
		?int $modelId = null,
		?bool $onGround = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Item,
			$itemId,
			ToolContext::fields([
				'kind' => $kind,
				'name' => $name,
				'x' => $x,
				'y' => $y,
				'z' => $z,
				'width' => $width,
				'depth' => $depth,
				'height' => $height,
				'rotation' => $rotation,
				'roomId' => $roomId,
				'groupId' => $groupId,
				'materialId' => $materialId,
				'params' => $params,
				'definitionId' => $definitionId,
				'modelId' => $modelId,
				'onGround' => $onGround,
			], $clear, self::Clearable),
		);
	}

	#[McpTool(name: 'delete_item', description: 'Delete an item (can be undone).', annotations: new ToolAnnotations(destructiveHint: true))]
	public function deleteItem(int $projectId, int $floorId, int $itemId): EditResultDto
	{
		return $this->editor->delete($this->context->user(), $this->context->floor($projectId, $floorId), ElementTypeEnum::Item, $itemId);
	}

	/**
	 * @param int|null $x footprint centre of the copy (default: 100 mm east of the original)
	 * @param int|null $y footprint centre of the copy
	 * @param string|null $name name of the copy
	 */
	#[McpTool(name: 'duplicate_item', description: 'Copy an item with all its settings, optionally to another position.')]
	public function duplicateItem(
		int $projectId,
		int $floorId,
		int $itemId,
		?int $x = null,
		?int $y = null,
		?string $name = null,
	): EditResultDto
	{
		return $this->editor->duplicate(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Item,
			$itemId,
			ToolContext::fields(['x' => $x, 'y' => $y, 'name' => $name]),
		);
	}
}
