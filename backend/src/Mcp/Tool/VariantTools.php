<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Service\Editor\VariantEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Variant sets: alternatives of one piece of furniture (e.g. the kitchen with or without its island). Each
 * variant is an item group; only the set's active variant is shown, the items of the others are hidden.
 */
final readonly class VariantTools
{
	public function __construct(private ToolContext $context, private VariantEditor $variantEditor, private SceneEditor $editor)
	{
	}

	/**
	 * @param string $name label shown to the user, e.g. "Kitchen layout"
	 * @param list<int> $groupIds groups (from get_floor) that become the variants; the first is shown
	 */
	#[McpTool(
		name: 'create_variant_set',
		description: 'Make alternatives of one piece of furniture from item groups (create_group first); only one is shown at a time. '
			. 'Items shared by all variants stay outside the set.',
	)]
	public function createVariantSet(int $projectId, int $floorId, string $name, array $groupIds): EditResultDto
	{
		return $this->variantEditor->createVariantSet(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			$name,
			$groupIds,
		);
	}

	/**
	 * @param int|null $fromGroupId variant to copy with its items (omit for an empty variant)
	 * @param string|null $name name of the new variant
	 */
	#[McpTool(
		name: 'add_variant',
		description: 'Add a variant to a variant set and show it: a copy of a variant (in place) or an empty one. '
			. 'Then change its items, or add items with groupId set to the new variant.',
	)]
	public function addVariant(
		int $projectId,
		int $floorId,
		int $variantSetId,
		?int $fromGroupId = null,
		?string $name = null,
	): EditResultDto
	{
		return $this->variantEditor->addVariant(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			$variantSetId,
			$fromGroupId,
			$name,
		);
	}

	/** @param int $groupId the variant (group) to show */
	#[McpTool(name: 'switch_variant', description: 'Show another variant of a variant set; the items of the others are hidden.')]
	public function switchVariant(int $projectId, int $floorId, int $variantSetId, int $groupId): EditResultDto
	{
		return $this->variantEditor->switchVariant(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			$variantSetId,
			$groupId,
		);
	}

	#[McpTool(name: 'rename_variant_set', description: 'Rename a variant set (rename a variant with rename_group).')]
	public function renameVariantSet(int $projectId, int $floorId, int $variantSetId, string $name): EditResultDto
	{
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::VariantSet,
			$variantSetId,
			['name' => $name],
		);
	}

	#[McpTool(
		name: 'delete_variant_set',
		description: 'Decide a variant set for its shown variant: the other variants are deleted with their items, the shown one '
			. 'stays as a plain group (can be undone). To drop a single variant use delete_group.',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteVariantSet(int $projectId, int $floorId, int $variantSetId): EditResultDto
	{
		return $this->variantEditor->deleteVariantSet($this->context->user(), $this->context->floor($projectId, $floorId), $variantSetId);
	}
}
