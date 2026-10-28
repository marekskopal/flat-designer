<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Stairs from a floor up to the next one; they cut their void through the floor above. */
final readonly class StairTools
{
	private const array Clearable = ['risers', 'firstFlight', 'treadMaterialId', 'structureMaterialId', 'rise'];

	private const string Rise = 'The stair climbs from its floor to the next one up (get_floor\'s stairRise, mm: the floors\' elevations, '
		. 'or the floor\'s tallest wall plus a 250 mm slab on the top floor); risers null splits that into as few steps as keep '
		. 'each at most 180 mm (at least 2), a count given must make each 140–220 mm. It cuts a void as large as its footprint '
		. 'through the floor above. A stair with a rise of its own (garden steps, steps off a deck, a stair down the slope) climbs '
		. 'that far from baseZ instead, stays on its floor and cuts no void.';

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param int $x plan mm: the middle of the first step's front edge (the foot of the stair)
	 * @param int $y plan mm
	 * @param int|null $rotation degrees counter-clockwise of the walking-up direction of the first flight: 0 walks up north (+y),
	 *   90 west, 180 south, 270 east (default 0)
	 * @param string|null $shape "Straight" (default) | "LShaped" (two flights round a quarter landing) | "UShaped" (two flights
	 *   round a half landing, side by side) | "Spiral" (round a centre column)
	 * @param int|null $width mm, a flight's width, 600–3000 (default 900); a spiral's diameter, 1200–4000 (default 1600)
	 * @param int|null $going mm, a tread's depth, 200–350 (default 270)
	 * @param int|null $risers number of risers, 2–60; null (default) counts them from the rise
	 * @param int|null $firstFlight L and U: risers up to the landing; null (default) halves them
	 * @param string|null $turn "Left" (default) | "Right": which way an L or U stair turns at its landing, or a spiral winds,
	 *   walking up
	 * @param int|null $gap U: mm between the two flights, 0–1000 (default 100)
	 * @param string|null $construction "Solid" (a concrete flight, default) | "Stringers" (treads between two side boards) |
	 *   "Open" (floating treads on a spine)
	 * @param string|null $railing "Right" (default) | "Left" | "Both" | "None", seen walking up; it also runs round the void upstairs
	 * @param int|null $treadMaterialId the treads, from list_materials (default the structure's)
	 * @param int|null $structureMaterialId the flight, stringers or spine, from list_materials (default concrete grey)
	 * @param int|null $rise a rise of its own, mm (100–6000): the stair does not climb to the next floor (garden or deck steps); default none
	 * @param int|null $baseZ where its first riser stands, mm above (+) or below (−) the floor's level (default 0)
	 */
	#[McpTool(name: 'add_stair', description: 'Add stairs on a floor, from it up to the next floor. ' . self::Rise)]
	public function addStair(
		int $projectId,
		int $floorId,
		int $x,
		int $y,
		?int $rotation = null,
		?string $shape = null,
		?string $name = null,
		?int $width = null,
		?int $going = null,
		?int $risers = null,
		?int $firstFlight = null,
		?string $turn = null,
		?int $gap = null,
		?string $construction = null,
		?string $railing = null,
		?int $treadMaterialId = null,
		?int $structureMaterialId = null,
		?int $rise = null,
		?int $baseZ = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Stair,
			ToolContext::fields([
				'x' => $x,
				'y' => $y,
				'rotation' => $rotation,
				'shape' => $shape,
				'name' => $name,
				'width' => $width,
				'going' => $going,
				'risers' => $risers,
				'firstFlight' => $firstFlight,
				'turn' => $turn,
				'gap' => $gap,
				'construction' => $construction,
				'railing' => $railing,
				'treadMaterialId' => $treadMaterialId,
				'structureMaterialId' => $structureMaterialId,
				'rise' => $rise,
				'baseZ' => $baseZ,
			]),
		);
	}

	/**
	 * @param string|null $shape "Straight" | "LShaped" | "UShaped" | "Spiral" (a spiral's width is its diameter, 1200–4000;
	 *   a shape change keeps the width where the new shape allows it, else takes the shape's default)
	 * @param int|null $rotation degrees counter-clockwise of the walking-up direction (0 = north)
	 * @param int|null $risers number of risers (clear it to count them from the rise)
	 * @param list<string>|null $clear fields to set to none: "risers" (counted from the rise), "firstFlight" (halved),
	 *   "treadMaterialId", "structureMaterialId", "rise" (to the next floor again)
	 * @param int|null $rise a rise of its own, mm (100–6000): the stair does not climb to the next floor (garden or deck steps); default none
	 * @param int|null $baseZ where its first riser stands, mm above (+) or below (−) the floor's level (default 0)
	 */
	#[McpTool(name: 'update_stair', description: 'Change stairs. Only the given fields change. ' . self::Rise)]
	public function updateStair(
		int $projectId,
		int $floorId,
		int $stairId,
		?string $name = null,
		?string $shape = null,
		?int $x = null,
		?int $y = null,
		?int $rotation = null,
		?int $width = null,
		?int $going = null,
		?int $risers = null,
		?int $firstFlight = null,
		?string $turn = null,
		?int $gap = null,
		?string $construction = null,
		?string $railing = null,
		?int $treadMaterialId = null,
		?int $structureMaterialId = null,
		?int $rise = null,
		?int $baseZ = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Stair,
			$stairId,
			ToolContext::fields(
				[
					'name' => $name,
					'shape' => $shape,
					'x' => $x,
					'y' => $y,
					'rotation' => $rotation,
					'width' => $width,
					'going' => $going,
					'risers' => $risers,
					'firstFlight' => $firstFlight,
					'turn' => $turn,
					'gap' => $gap,
					'construction' => $construction,
					'railing' => $railing,
					'treadMaterialId' => $treadMaterialId,
					'structureMaterialId' => $structureMaterialId,
					'rise' => $rise,
					'baseZ' => $baseZ,
				],
				$clear,
				self::Clearable,
			),
		);
	}

	#[McpTool(name: 'delete_stair', description: 'Delete stairs (can be undone).', annotations: new ToolAnnotations(destructiveHint: true))]
	public function deleteStair(int $projectId, int $floorId, int $stairId): EditResultDto
	{
		return $this->editor->delete($this->context->user(), $this->context->floor($projectId, $floorId), ElementTypeEnum::Stair, $stairId);
	}
}
