<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Doors, passages and windows in walls. */
final readonly class OpeningTools
{
	private const array Clearable = ['leafWidth', 'hinge', 'side', 'handle', 'sillBoard'];

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param int $wallId wall to cut the opening into (from get_floor)
	 * @param string $type "Door" (one swinging leaf) | "DoubleDoor" (two leaves hinged on the jambs) | "SlidingDoor" (leaf hung on a wall face, sliding along it) | "PocketDoor" (leaf sliding into the wall) | "Passage" (doorway without a leaf) | "Window"
	 * @param int $offset mm from the wall's start point (along the centre line) to the near edge of the opening
	 * @param int|null $width mm (door default 900, double door 1400, window 1200)
	 * @param int|null $height mm (door default 1970, passage 2100, window 1200)
	 * @param int|null $sillHeight mm above the floor (door 0, window default 900)
	 * @param int|null $leafWidth Door: leaf width, mm; DoubleDoor: width of the leaf on `hinge` (none: equal halves)
	 * @param string|null $hinge "Start" (the opening's edge nearer the wall start) | "End". Door: edge carrying the leaf; DoubleDoor: the wider leaf's edge; SlidingDoor/PocketDoor: the way the leaf slides open (the wall on that side must be free for it)
	 * @param string|null $side "Left" | "Right" of the wall when walking from its start to end. Door/DoubleDoor/walkable window: where the leaves swing to; SlidingDoor: the face the leaf hangs on
	 * @param bool|null $entrance true for the main door of the floor (a walkthrough starts just inside it)
	 * @param bool|null $walkable window down to the floor (sill 0) only: its sashes open in the walkthrough, which can then pass through it (terrace/balcony doors)
	 * @param string|null $frameStyle doors: "Lining" (default) | "Architrave" (lining with casings) | "Flush" (hidden frame) | "None" (bare hole, a passage's default)
	 * @param string|null $handle doors with a leaf: "Lever" | "Knob" | "Pull" | "None"; none given: a lever, a pull on sliding and pocket doors
	 * @param int|null $mullions window: vertical glazing bars, 0–8 (default 1 = two panes; a walkable window 0 = one sash, 1 = two)
	 * @param int|null $transoms window: horizontal glazing bars, 0–4 (default 0)
	 * @param int|null $sillBoard window: how far the inner sill board reaches past the wall face on `side` (the interior, default Left), mm; exterior walls also get an outer sill. Default none. Two windows meeting at a wall corner, both reaching that wall end, form a corner window by themselves.
	 */
	#[McpTool(
		name: 'add_opening',
		description: 'Add a door, passage or window to a wall. It must fit within the wall and not overlap another opening.',
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
		?bool $walkable = null,
		?string $frameStyle = null,
		?string $handle = null,
		?int $mullions = null,
		?int $transoms = null,
		?int $sillBoard = null,
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
				'walkable' => $walkable,
				'frameStyle' => $frameStyle,
				'handle' => $handle,
				'mullions' => $mullions,
				'transoms' => $transoms,
				'sillBoard' => $sillBoard,
			]),
		);
	}

	/**
	 * @param string|null $type "Door" | "DoubleDoor" | "SlidingDoor" | "PocketDoor" | "Passage" | "Window" (see add_opening)
	 * @param int|null $offset mm from the wall's start point
	 * @param int|null $leafWidth Door: leaf width, mm; DoubleDoor: width of the leaf on `hinge`
	 * @param string|null $hinge "Start" | "End" (see add_opening: hinge edge, or the way a sliding leaf opens)
	 * @param string|null $side "Left" | "Right" (swing side of door leaves and walkable window sashes; the face a sliding door hangs on)
	 * @param bool|null $entrance true for the main door of the floor (a walkthrough starts just inside it)
	 * @param bool|null $walkable window down to the floor (sill 0) only: its sashes open in the walkthrough, which can then pass through it (terrace/balcony doors)
	 * @param string|null $frameStyle doors: "Lining" | "Architrave" | "Flush" | "None"
	 * @param string|null $handle doors with a leaf: "Lever" | "Knob" | "Pull" | "None"
	 * @param int|null $mullions window: vertical glazing bars, 0–8
	 * @param int|null $transoms window: horizontal glazing bars, 0–4
	 * @param int|null $sillBoard window: inner sill board's reach past the wall face, mm
	 * @param list<string>|null $clear fields to set to none: "leafWidth", "hinge", "side", "handle" (the type's default handle), "sillBoard"
	 */
	#[McpTool(
		name: 'update_opening',
		description: 'Change a door, passage or window (it stays on its wall). Only the given fields change.',
	)]
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
		?bool $walkable = null,
		?string $frameStyle = null,
		?string $handle = null,
		?int $mullions = null,
		?int $transoms = null,
		?int $sillBoard = null,
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
				'walkable' => $walkable,
				'frameStyle' => $frameStyle,
				'handle' => $handle,
				'mullions' => $mullions,
				'transoms' => $transoms,
				'sillBoard' => $sillBoard,
			], $clear, self::Clearable),
		);
	}

	#[McpTool(
		name: 'delete_opening',
		description: 'Delete a door, passage or window (can be undone).',
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
