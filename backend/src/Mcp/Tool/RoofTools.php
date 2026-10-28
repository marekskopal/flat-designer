<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Roofs over a floor and the windows in them (FD-38). */
final readonly class RoofTools
{
	private const array Clearable = ['parapet', 'coveringMaterialId', 'soffitMaterialId'];

	private const string Shape = 'A roof covers a rectangle, its eaves line: width × depth centred on (x, y), the ridge along the width, '
		. 'turned by rotation. The underside is eaveHeight above the floor\'s level along the eaves line and rises at pitch inwards; '
		. 'it reaches overhang past the eaves and vergeOverhang past the gables. Every wall of the floor is cut at the underside of '
		. 'the roofs over it; a wall with upToRoof (add_wall, add_walls, update_wall) rises to it, as a gable does. An L- or T-shaped house takes two '
		. 'or three roofs.';

	private const string Placement = 'A roof window lies in the plane of a pitched roof under its centre, which is given in the roof\'s '
		. 'frame: along (mm along the ridge from the roof\'s centre) and across (mm across it, + towards the back, the side the '
		. 'roof\'s front faces away from; at rotation 0 the front faces south), so it moves with the roof. It must stay 300 mm (in plan) '
		. 'inside its plane, clear of the eaves, verges, ridge and hips, and clear of the plane\'s other windows; width runs across '
		. 'the slope, length up it.';

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param string|null $shape "Gable" (default: two planes meeting at the ridge, gables at its ends) | "Hip" (four planes rising
	 *   from all the eaves; a pyramid over a square) | "Shed" (one plane rising from the front eaves to the back) | "Flat"
	 * @param int|null $x plan mm, the centre of the eaves rectangle (default: the rectangle round the floor's walls' outer faces)
	 * @param int|null $y plan mm
	 * @param int|null $rotation degrees counter-clockwise of the ridge from plan east (+x); the front, where a shed is low,
	 *   faces south at 0 (default: along the longest exterior wall, the ridge along the longer side)
	 * @param int|null $width mm along the ridge, 1000–60000
	 * @param int|null $depth mm across it, 1000–60000
	 * @param int|null $eaveHeight mm of the underside above the floor's level along the eaves line (default: the tallest wall)
	 * @param int|null $pitch degrees, 5–75 (default 35); unused by a flat roof
	 * @param int|null $overhang mm past the eaves, 0–2000 (default 400)
	 * @param int|null $vergeOverhang mm past the gables (and a shed's sides), 0–2000 (default 200)
	 * @param int|null $thickness mm of rafters and covering, square to the slope, 50–1000 (default 250)
	 * @param int|null $parapet a flat roof's parapet, mm above it, 100–2000 (default none)
	 * @param int|null $coveringMaterialId from list_materials, e.g. roof-tiles-clay, roof-slate, roof-metal-seam, roof-membrane
	 *   (default clay tiles; a flat roof membrane)
	 * @param int|null $soffitMaterialId the underside of the overhang, from list_materials (default painted timber)
	 */
	#[McpTool(name: 'add_roof', description: 'Add a roof over a floor; without a size it covers the floor\'s walls. ' . self::Shape)]
	public function addRoof(
		int $projectId,
		int $floorId,
		?string $shape = null,
		?string $name = null,
		?int $x = null,
		?int $y = null,
		?int $rotation = null,
		?int $width = null,
		?int $depth = null,
		?int $eaveHeight = null,
		?int $pitch = null,
		?int $overhang = null,
		?int $vergeOverhang = null,
		?int $thickness = null,
		?int $parapet = null,
		?int $coveringMaterialId = null,
		?int $soffitMaterialId = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Roof,
			ToolContext::fields([
				'shape' => $shape,
				'name' => $name,
				'x' => $x,
				'y' => $y,
				'rotation' => $rotation,
				'width' => $width,
				'depth' => $depth,
				'eaveHeight' => $eaveHeight,
				'pitch' => $pitch,
				'overhang' => $overhang,
				'vergeOverhang' => $vergeOverhang,
				'thickness' => $thickness,
				'parapet' => $parapet,
				'coveringMaterialId' => $coveringMaterialId,
				'soffitMaterialId' => $soffitMaterialId,
			]),
		);
	}

	/**
	 * @param string|null $shape "Gable" | "Hip" | "Shed" | "Flat"
	 * @param int|null $rotation degrees counter-clockwise of the ridge from plan east (+x)
	 * @param list<string>|null $clear fields to set to none: "parapet", "coveringMaterialId", "soffitMaterialId"
	 */
	#[McpTool(
		name: 'update_roof',
		description: 'Change a roof. Only the given fields change; its windows move with it but are not checked again. ' . self::Shape,
	)]
	public function updateRoof(
		int $projectId,
		int $floorId,
		int $roofId,
		?string $name = null,
		?string $shape = null,
		?int $x = null,
		?int $y = null,
		?int $rotation = null,
		?int $width = null,
		?int $depth = null,
		?int $eaveHeight = null,
		?int $pitch = null,
		?int $overhang = null,
		?int $vergeOverhang = null,
		?int $thickness = null,
		?int $parapet = null,
		?int $coveringMaterialId = null,
		?int $soffitMaterialId = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Roof,
			$roofId,
			ToolContext::fields(
				[
					'name' => $name,
					'shape' => $shape,
					'x' => $x,
					'y' => $y,
					'rotation' => $rotation,
					'width' => $width,
					'depth' => $depth,
					'eaveHeight' => $eaveHeight,
					'pitch' => $pitch,
					'overhang' => $overhang,
					'vergeOverhang' => $vergeOverhang,
					'thickness' => $thickness,
					'parapet' => $parapet,
					'coveringMaterialId' => $coveringMaterialId,
					'soffitMaterialId' => $soffitMaterialId,
				],
				$clear,
				self::Clearable,
			),
		);
	}

	#[McpTool(
		name: 'delete_roof',
		description: 'Delete a roof together with its windows (can be undone).',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteRoof(int $projectId, int $floorId, int $roofId): EditResultDto
	{
		return $this->editor->delete($this->context->user(), $this->context->floor($projectId, $floorId), ElementTypeEnum::Roof, $roofId);
	}

	/**
	 * @param int|null $along mm along the ridge from the roof's centre (default 0)
	 * @param int|null $across mm across the ridge from the roof's centre, + towards the back (default: low on the front plane, 400 mm
	 *   in plan above its eaves)
	 * @param int|null $width mm across the slope, 300–3000 (default 780)
	 * @param int|null $length mm up the slope, 300–3000 (default 1180)
	 * @param string|null $type "CentrePivot" (default) | "TopHung": how the sash opens, which shows in its look only
	 * @param int|null $frameMaterialId from list_materials (default white-painted timber)
	 */
	#[McpTool(name: 'add_roof_window', description: 'Add a window to a roof. ' . self::Placement)]
	public function addRoofWindow(
		int $projectId,
		int $floorId,
		int $roofId,
		?int $along = null,
		?int $across = null,
		?string $name = null,
		?int $width = null,
		?int $length = null,
		?string $type = null,
		?int $frameMaterialId = null,
	): EditResultDto {
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::RoofWindow,
			ToolContext::fields([
				'roofId' => $roofId,
				'along' => $along,
				'across' => $across,
				'name' => $name,
				'width' => $width,
				'length' => $length,
				'type' => $type,
				'frameMaterialId' => $frameMaterialId,
			]),
		);
	}

	/**
	 * @param string|null $type "CentrePivot" | "TopHung"
	 * @param list<string>|null $clear fields to set to none: "frameMaterialId"
	 */
	#[McpTool(name: 'update_roof_window', description: 'Change a roof window. Only the given fields change. ' . self::Placement)]
	public function updateRoofWindow(
		int $projectId,
		int $floorId,
		int $roofWindowId,
		?int $along = null,
		?int $across = null,
		?string $name = null,
		?int $width = null,
		?int $length = null,
		?string $type = null,
		?int $frameMaterialId = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::RoofWindow,
			$roofWindowId,
			ToolContext::fields(
				[
					'along' => $along,
					'across' => $across,
					'name' => $name,
					'width' => $width,
					'length' => $length,
					'type' => $type,
					'frameMaterialId' => $frameMaterialId,
				],
				$clear,
				['frameMaterialId'],
			),
		);
	}

	#[McpTool(
		name: 'delete_roof_window',
		description: 'Delete a roof window (can be undone).',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteRoofWindow(int $projectId, int $floorId, int $roofWindowId): EditResultDto
	{
		return $this->editor->delete(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::RoofWindow,
			$roofWindowId,
		);
	}
}
