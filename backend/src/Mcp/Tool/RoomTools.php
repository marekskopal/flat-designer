<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\Drawing\RoomOutliner;
use FlatDesigner\Service\Editor\Element\WallFinishBands;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/** Rooms: floor areas with their finishes. */
final readonly class RoomTools
{
	private const array Clearable = [
		'ceilingHeight',
		'floorMaterialId',
		'tileOriginX',
		'tileOriginY',
		'ceilingMaterialId',
		'vaultSpacing',
		'beamMaterialId',
		'skirtingHeight',
		'skirtingMaterialId',
		'soffitMaterialId',
	];

	private const array WallFinishesSchema = [
		'type' => ['array', 'null'],
		'description' => 'horizontal bands around the room\'s walls, mm above the floor (e.g. tiling from 0 to 1200); [] removes them',
		'items' => WallFinishBands::BandSchema,
		'default' => null,
	];

	public function __construct(private ToolContext $context, private SceneEditor $editor, private RoomOutliner $roomOutliner)
	{
	}

	/**
	 * @param int $x plan x (mm) of a point inside the room
	 * @param int $y plan y (mm) of that point
	 * @param string|null $name e.g. "Kitchen" (default "Room")
	 * @param string|null $code short room code, e.g. "B_07"
	 * @param string|null $type "Living" | "Bedroom" | "Bathroom" | "Toilet" | "Hallway" | "Terrace" | "Garden" | "Path" | "Driveway" (the last four are open-air)
	 * @param int|null $ceilingHeight mm (default 2600)
	 * @param int|null $floorMaterialId floor surface from list_materials
	 */
	#[McpTool(
		name: 'detect_room',
		description: 'Add a room whose outline is the inner faces of the walls enclosing the point (x, y), mitred corners included. '
			. 'Fails when the point is inside a wall or the walls around it do not close (doors do not matter).',
	)]
	public function detectRoom(
		int $projectId,
		int $floorId,
		int $x,
		int $y,
		?string $name = null,
		?string $code = null,
		?string $type = null,
		?int $ceilingHeight = null,
		?int $floorMaterialId = null,
	): EditResultDto {
		$floor = $this->context->floor($projectId, $floorId);

		return $this->roomOutliner->createRoom($this->context->user(), $floor, $x, $y, ToolContext::fields([
			'name' => $name,
			'code' => $code,
			'type' => $type,
			'ceilingHeight' => $ceilingHeight,
			'floorMaterialId' => $floorMaterialId,
		]));
	}

	/**
	 * @param int $x plan x (mm) of a point inside the room
	 * @param int $y plan y (mm) of that point
	 * @return array{polygon: list<array{int, int}>}
	 */
	#[McpTool(
		name: 'find_room_outline',
		description: 'The outline detect_room would give for the point (x, y), as {polygon: [[x, y], …]}, without adding anything.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function findRoomOutline(int $projectId, int $floorId, int $x, int $y): array
	{
		return ['polygon' => $this->roomOutliner->outline($this->context->floor($projectId, $floorId), $x, $y)];
	}

	/**
	 * @param string $name e.g. "Kitchen"
	 * @param list<list<int>> $polygon floor outline as [[x, y], …] in mm, at least 3 points, not crossing itself
	 * @param string|null $code short room code, e.g. "B_07"
	 * @param string|null $type "Living" | "Bedroom" | "Bathroom" | "Toilet" | "Hallway" | "Terrace" | "Garden" | "Path" | "Driveway" (the last four are open-air)
	 * @param int|null $ceilingHeight mm (default 2600)
	 * @param int|null $floorMaterialId floor surface from list_materials
	 * @param list<array{materialId: int, bottom: int, top: int}>|null $wallFinishes horizontal bands around the room's walls, mm above the floor (e.g. tiling 0–1200); a wall face may override them (update_wall leftFinishes / rightFinishes)
	 * @param int|null $tileOriginX plan x (mm) of a point where tile joints cross; floor tiles, wall bands and tiled items share the grid
	 * @param int|null $tileOriginY plan y (mm) of that point
	 * @param int|null $floorAngle floor pattern turned about the tile origin, degrees counter-clockwise (0–179; 0 = planks running along plan y, 90 = along plan x); wall bands keep their joints
	 * @param int|null $floorElevation mm the room's floor lies above (+) or below (−) the floor's level, e.g. -150 for a terrace a step down, 450 for a raised deck (−3000…3000, default 0); its ceiling height counts from it, items keep their own z
	 * @param int|null $ceilingMaterialId ceiling surface from list_materials (default white plaster; the vaults' material when vaulted, e.g. exposed brick)
	 * @param int|null $vaultSpacing vaulted ceiling: mm between the beams (300–3000); the vaults' crowns are at ceilingHeight. Default none: a flat ceiling
	 * @param int|null $vaultAngle vaulted ceiling: direction the beams run, degrees counter-clockwise from plan x (0 = beams running east–west)
	 * @param int|null $vaultRise vaulted ceiling: rise of each vault from its springing to its crown, mm (default 130)
	 * @param int|null $beamWidth vaulted ceiling: width of the beams, mm (default 80)
	 * @param int|null $beamDrop vaulted ceiling: how far the beams hang below the vaults' springing, mm (default 30)
	 * @param int|null $beamMaterialId vaulted ceiling: material of the beams (default white paint)
	 * @param int|null $skirtingHeight skirting boards along the room's walls (doors left free), mm high (20–400); default none
	 * @param int|null $skirtingMaterialId material of the skirting (default white)
	 * @param int|null $soffitMaterialId the underside and edge of the room's slab where it overhangs the floor below (an oriel, a
	 *   balcony), from list_materials (default the project's façade material)
	 */
	#[McpTool(name: 'add_room', description: 'Add a room (floor area) to a floor.')]
	public function addRoom(
		int $projectId,
		int $floorId,
		string $name,
		array $polygon,
		?string $code = null,
		?string $type = null,
		?int $ceilingHeight = null,
		?int $floorMaterialId = null,
		#[Schema(definition: self::WallFinishesSchema)]
		?array $wallFinishes = null,
		?int $tileOriginX = null,
		?int $tileOriginY = null,
		?int $floorAngle = null,
		?int $floorElevation = null,
		?int $ceilingMaterialId = null,
		?int $vaultSpacing = null,
		?int $vaultAngle = null,
		?int $vaultRise = null,
		?int $beamWidth = null,
		?int $beamDrop = null,
		?int $beamMaterialId = null,
		?int $skirtingHeight = null,
		?int $skirtingMaterialId = null,
		?int $soffitMaterialId = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Room,
			ToolContext::fields([
				'name' => $name,
				'polygon' => $polygon,
				'code' => $code,
				'type' => $type,
				'ceilingHeight' => $ceilingHeight,
				'floorMaterialId' => $floorMaterialId,
				'wallFinishes' => $wallFinishes,
				'tileOriginX' => $tileOriginX,
				'tileOriginY' => $tileOriginY,
				'floorAngle' => $floorAngle,
				'floorElevation' => $floorElevation,
				'ceilingMaterialId' => $ceilingMaterialId,
				'vaultSpacing' => $vaultSpacing,
				'vaultAngle' => $vaultAngle,
				'vaultRise' => $vaultRise,
				'beamWidth' => $beamWidth,
				'beamDrop' => $beamDrop,
				'beamMaterialId' => $beamMaterialId,
				'skirtingHeight' => $skirtingHeight,
				'skirtingMaterialId' => $skirtingMaterialId,
				'soffitMaterialId' => $soffitMaterialId,
			]),
		);
	}

	/**
	 * @param list<list<int>>|null $polygon new outline as [[x, y], …] in mm
	 * @param string|null $type "Living" | "Bedroom" | "Bathroom" | "Toilet" | "Hallway" | "Terrace" | "Garden" | "Path" | "Driveway" (the last four are open-air)
	 * @param list<array{materialId: int, bottom: int, top: int}>|null $wallFinishes replaces all bands; [] removes them
	 * @param int|null $tileOriginX plan x (mm) of a point where tile joints cross (floor, wall bands and tiled items share the grid)
	 * @param int|null $tileOriginY plan y (mm) of that point
	 * @param int|null $floorAngle floor pattern turned about the tile origin, degrees counter-clockwise (0–179; 0 = planks running along plan y, 90 = along plan x); wall bands keep their joints
	 * @param int|null $floorElevation mm the room's floor lies above (+) or below (−) the floor's level, e.g. -150 for a terrace a step down, 450 for a raised deck (−3000…3000, default 0); its ceiling height counts from it, items keep their own z
	 * @param int|null $ceilingMaterialId ceiling surface from list_materials (default white plaster; the vaults' material when vaulted, e.g. exposed brick)
	 * @param int|null $vaultSpacing vaulted ceiling: mm between the beams (300–3000); the vaults' crowns are at ceilingHeight. Default none: a flat ceiling
	 * @param int|null $vaultAngle vaulted ceiling: direction the beams run, degrees counter-clockwise from plan x (0 = beams running east–west)
	 * @param int|null $vaultRise vaulted ceiling: rise of each vault from its springing to its crown, mm (default 130)
	 * @param int|null $beamWidth vaulted ceiling: width of the beams, mm (default 80)
	 * @param int|null $beamDrop vaulted ceiling: how far the beams hang below the vaults' springing, mm (default 30)
	 * @param int|null $beamMaterialId vaulted ceiling: material of the beams (default white paint)
	 * @param int|null $skirtingHeight skirting boards along the room's walls (doors left free), mm high (20–400); default none
	 * @param int|null $skirtingMaterialId material of the skirting (default white)
	 * @param int|null $soffitMaterialId the underside and edge of the room's slab where it overhangs the floor below (an oriel, a
	 *   balcony), from list_materials (default the project's façade material)
	 * @param list<string>|null $clear fields to set to none: "ceilingHeight" (open air), "floorMaterialId", "tileOriginX", "tileOriginY", "ceilingMaterialId", "vaultSpacing" (a flat ceiling again), "beamMaterialId", "skirtingHeight", "skirtingMaterialId", "soffitMaterialId"
	 */
	#[McpTool(name: 'update_room', description: 'Change a room. Only the given fields change.')]
	public function updateRoom(
		int $projectId,
		int $floorId,
		int $roomId,
		?string $name = null,
		?array $polygon = null,
		?string $code = null,
		?string $type = null,
		?int $ceilingHeight = null,
		?int $floorMaterialId = null,
		#[Schema(definition: self::WallFinishesSchema)]
		?array $wallFinishes = null,
		?int $tileOriginX = null,
		?int $tileOriginY = null,
		?int $floorAngle = null,
		?int $floorElevation = null,
		?int $ceilingMaterialId = null,
		?int $vaultSpacing = null,
		?int $vaultAngle = null,
		?int $vaultRise = null,
		?int $beamWidth = null,
		?int $beamDrop = null,
		?int $beamMaterialId = null,
		?int $skirtingHeight = null,
		?int $skirtingMaterialId = null,
		?int $soffitMaterialId = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Room,
			$roomId,
			ToolContext::fields([
				'name' => $name,
				'polygon' => $polygon,
				'code' => $code,
				'type' => $type,
				'ceilingHeight' => $ceilingHeight,
				'floorMaterialId' => $floorMaterialId,
				'wallFinishes' => $wallFinishes,
				'tileOriginX' => $tileOriginX,
				'tileOriginY' => $tileOriginY,
				'floorAngle' => $floorAngle,
				'floorElevation' => $floorElevation,
				'ceilingMaterialId' => $ceilingMaterialId,
				'vaultSpacing' => $vaultSpacing,
				'vaultAngle' => $vaultAngle,
				'vaultRise' => $vaultRise,
				'beamWidth' => $beamWidth,
				'beamDrop' => $beamDrop,
				'beamMaterialId' => $beamMaterialId,
				'skirtingHeight' => $skirtingHeight,
				'skirtingMaterialId' => $skirtingMaterialId,
				'soffitMaterialId' => $soffitMaterialId,
			], $clear, self::Clearable),
		);
	}

	#[McpTool(
		name: 'delete_room',
		description: 'Delete a room; its items stay but lose the room link (can be undone).',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteRoom(int $projectId, int $floorId, int $roomId): EditResultDto
	{
		return $this->editor->delete($this->context->user(), $this->context->floor($projectId, $floorId), ElementTypeEnum::Room, $roomId);
	}
}
