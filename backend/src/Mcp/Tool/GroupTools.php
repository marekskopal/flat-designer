<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\GroupEditor;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Item groups: items forming one piece of furniture (a kitchen run, a hall stand) that move, copy and
 * delete together. Each operation is one change (one undo step), listing every element it changed in `batch`.
 */
final readonly class GroupTools
{
	public function __construct(private ToolContext $context, private GroupEditor $groupEditor, private SceneEditor $editor)
	{
	}

	/**
	 * @param string $name label shown to the user, e.g. "Hall stand"
	 * @param list<int> $itemIds items to put in the group (taken out of their previous groups); may be empty
	 */
	#[McpTool(name: 'create_group', description: 'Group items into one piece of furniture that moves, copies and deletes together.')]
	public function createGroup(int $projectId, int $floorId, string $name, array $itemIds): EditResultDto
	{
		return $this->groupEditor->createGroup($this->context->user(), $this->context->floor($projectId, $floorId), $name, $itemIds);
	}

	#[McpTool(name: 'rename_group', description: 'Rename a group.')]
	public function renameGroup(int $projectId, int $floorId, int $groupId, string $name): EditResultDto
	{
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Group,
			$groupId,
			['name' => $name],
		);
	}

	/**
	 * @param int|null $dx mm to move east (negative: west)
	 * @param int|null $dy mm to move north (negative: south)
	 * @param int|null $rotation degrees to turn counter-clockwise about the centre of the group's footprint
	 */
	#[McpTool(
		name: 'move_group',
		description: 'Move and/or turn all items of a group together. Add items to a group or take them out with update_item groupId.',
	)]
	public function moveGroup(
		int $projectId,
		int $floorId,
		int $groupId,
		?int $dx = null,
		?int $dy = null,
		?int $rotation = null,
	): EditResultDto
	{
		return $this->groupEditor->moveGroup(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			$groupId,
			$dx ?? 0,
			$dy ?? 0,
			$rotation ?? 0,
		);
	}

	/**
	 * @param int|null $dx mm east of the original (default 100)
	 * @param int|null $dy mm north of the original
	 * @param string|null $name name of the copy (default: the original's)
	 */
	#[McpTool(name: 'duplicate_group', description: 'Copy a group with all its items, shifted by dx/dy.')]
	public function duplicateGroup(
		int $projectId,
		int $floorId,
		int $groupId,
		?int $dx = null,
		?int $dy = null,
		?string $name = null,
	): EditResultDto {
		return $this->groupEditor->duplicateGroup(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			$groupId,
			$dx ?? 100,
			$dy ?? 0,
			$name,
		);
	}

	#[McpTool(name: 'ungroup', description: 'Dissolve a group; its items stay where they are.')]
	public function ungroup(int $projectId, int $floorId, int $groupId): EditResultDto
	{
		return $this->groupEditor->ungroup($this->context->user(), $this->context->floor($projectId, $floorId), $groupId);
	}

	#[McpTool(
		name: 'delete_group',
		description: 'Delete a group together with all its items (can be undone). Use ungroup to keep the items.',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteGroup(int $projectId, int $floorId, int $groupId): EditResultDto
	{
		return $this->groupEditor->deleteGroup($this->context->user(), $this->context->floor($projectId, $floorId), $groupId);
	}
}
