<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\Drawing\WallDrawer;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Service\Request\BodyReader;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Walls, given by their centre line. */
final readonly class WallTools
{
	public function __construct(private ToolContext $context, private SceneEditor $editor, private WallDrawer $wallDrawer)
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
	 * @param list<list<int>> $points centre-line points as [[x, y], …] in mm; each wall runs from one point to the next
	 * @param bool|null $closed true adds a last wall from the last point back to the first (default false)
	 * @param int|null $thickness mm, for every wall (default 100)
	 * @param int|null $height mm, for every wall (default 2600)
	 * @param string|null $type "Exterior" | "Interior" | "Shaft" (default Interior)
	 * @param int|null $materialId surface material from list_materials
	 */
	#[McpTool(
		name: 'add_walls',
		description: 'Draw a run of straight walls through the given centre-line points, as one change (one undo step, listed in `batch`). '
			. 'Walls sharing an end point join in a mitred corner. Put rooms inside with detect_room.',
	)]
	public function addWalls(
		int $projectId,
		int $floorId,
		array $points,
		?bool $closed = null,
		?int $thickness = null,
		?int $height = null,
		?string $type = null,
		?string $name = null,
		?int $materialId = null,
	): EditResultDto {
		return $this->wallDrawer->drawWalls(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			BodyReader::pointList(['points' => $points], 'points'),
			$closed ?? false,
			ToolContext::fields([
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
	#[McpTool(
		name: 'update_wall',
		description: 'Change a wall. Only the given fields change. Moving the start point shifts the offsets of its doors and windows so they '
			. 'stay in place on the plan; they must still fit the wall.',
	)]
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
