<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Doors and windows in walls. */
final readonly class OpeningTools
{
	private const array Clearable = ['leafWidth', 'hinge', 'side'];

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param int $wallId wall to cut the opening into (from get_floor)
	 * @param string $type "Door" | "Window"
	 * @param int $offset mm from the wall's start point (along the centre line) to the near edge of the opening
	 * @param int|null $width mm (door default 900, window 1200)
	 * @param int|null $height mm (door default 1970, window 1200)
	 * @param int|null $sillHeight mm above the floor (door 0, window default 900)
	 * @param int|null $leafWidth door leaf width, mm
	 * @param string|null $hinge door: which edge carries the leaf, "Start" (nearer the wall start) | "End"
	 * @param string|null $side door: side the leaf swings to, "Left" | "Right" when walking from wall start to end
	 * @param bool|null $entrance true for the main door of the floor (a walkthrough starts just inside it)
	 */
	#[McpTool(
		name: 'add_opening',
		description: 'Add a door or window to a wall. It must fit within the wall and not overlap another opening.',
	)]
	public function addOpening(
		int $projectId,
		int $floorId,
		int $wallId,
		string $type,
		int $offset,
		?int $width = null,
		?int $height = null,
		?int $sillHeight = null,
		?string $name = null,
		?int $leafWidth = null,
		?string $hinge = null,
		?string $side = null,
		?bool $entrance = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Opening,
			ToolContext::fields([
				'wallId' => $wallId,
				'type' => $type,
				'offset' => $offset,
				'width' => $width,
				'height' => $height,
				'sillHeight' => $sillHeight,
				'name' => $name,
				'leafWidth' => $leafWidth,
				'hinge' => $hinge,
				'side' => $side,
				'entrance' => $entrance,
			]),
		);
	}

	/**
	 * @param string|null $type "Door" | "Window"
	 * @param int|null $offset mm from the wall's start point
	 * @param string|null $hinge "Start" | "End"
	 * @param string|null $side "Left" | "Right"
	 * @param bool|null $entrance true for the main door of the floor (a walkthrough starts just inside it)
	 * @param list<string>|null $clear fields to set to none: "leafWidth", "hinge", "side"
	 */
	#[McpTool(name: 'update_opening', description: 'Change a door or window (it stays on its wall). Only the given fields change.')]
	public function updateOpening(
		int $projectId,
		int $floorId,
		int $openingId,
		?string $type = null,
		?int $offset = null,
		?int $width = null,
		?int $height = null,
		?int $sillHeight = null,
		?string $name = null,
		?int $leafWidth = null,
		?string $hinge = null,
		?string $side = null,
		?bool $entrance = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Opening,
			$openingId,
			ToolContext::fields([
				'type' => $type,
				'offset' => $offset,
				'width' => $width,
				'height' => $height,
				'sillHeight' => $sillHeight,
				'name' => $name,
				'leafWidth' => $leafWidth,
				'hinge' => $hinge,
				'side' => $side,
				'entrance' => $entrance,
			], $clear, self::Clearable),
		);
	}

	#[McpTool(
		name: 'delete_opening',
		description: 'Delete a door or window (can be undone).',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteOpening(int $projectId, int $floorId, int $openingId): EditResultDto
	{
		return $this->editor->delete(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Opening,
			$openingId,
		);
	}
}
