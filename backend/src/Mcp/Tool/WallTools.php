<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Walls, given by their centre line. */
final readonly class WallTools
{
	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param int $startX centre line start, mm east
	 * @param int $startY centre line start, mm north
	 * @param int $endX centre line end, mm east
	 * @param int $endY centre line end, mm north
	 * @param int|null $thickness mm (default 100)
	 * @param int|null $height mm (default 2600)
	 * @param string|null $type "Exterior" | "Interior" | "Shaft" (default Interior)
	 * @param int|null $materialId surface material from list_materials
	 */
	#[McpTool(name: 'add_wall', description: 'Add a straight wall. Openings are placed along it from the start point.')]
	public function addWall(
		int $projectId,
		int $floorId,
		int $startX,
		int $startY,
		int $endX,
		int $endY,
		?int $thickness = null,
		?int $height = null,
		?string $type = null,
		?string $name = null,
		?int $materialId = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Wall,
			ToolContext::fields([
				'startX' => $startX,
				'startY' => $startY,
				'endX' => $endX,
				'endY' => $endY,
				'thickness' => $thickness,
				'height' => $height,
				'type' => $type,
				'name' => $name,
				'materialId' => $materialId,
			]),
		);
	}

	/**
	 * @param string|null $type "Exterior" | "Interior" | "Shaft"
	 * @param list<string>|null $clear fields to set to none: "materialId"
	 */
	#[McpTool(name: 'update_wall', description: 'Change a wall. Only the given fields change. Its openings must still fit.')]
	public function updateWall(
		int $projectId,
		int $floorId,
		int $wallId,
		?int $startX = null,
		?int $startY = null,
		?int $endX = null,
		?int $endY = null,
		?int $thickness = null,
		?int $height = null,
		?string $type = null,
		?string $name = null,
		?int $materialId = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Wall,
			$wallId,
			ToolContext::fields([
				'startX' => $startX,
				'startY' => $startY,
				'endX' => $endX,
				'endY' => $endY,
				'thickness' => $thickness,
				'height' => $height,
				'type' => $type,
				'name' => $name,
				'materialId' => $materialId,
			], $clear, ['materialId']),
		);
	}

	#[McpTool(
		name: 'delete_wall',
		description: 'Delete a wall together with its doors and windows (can be undone).',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteWall(int $projectId, int $floorId, int $wallId): EditResultDto
	{
		return $this->editor->delete($this->context->user(), $this->context->floor($projectId, $floorId), ElementTypeEnum::Wall, $wallId);
	}
}
