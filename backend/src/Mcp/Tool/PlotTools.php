<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\ContourLines;
use FlatDesigner\Service\Editor\Element\PlotHandler;
use FlatDesigner\Service\Editor\SceneEditor;
use FlatDesigner\Service\Editor\SpotHeights;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/** Plots: the boundary of the land around the building and its ground, flat or sloping (spot heights, FD-86). */
final readonly class PlotTools
{
	private const array Clearable = ['groundMaterialId'];

	private const array TerrainSchema = [
		'type' => ['array', 'null'],
		'description' => 'spot heights of a sloping ground as [[x, y, z], …]: plan mm and z in mm above (+) or below (−) the '
			. 'floor\'s level, at least 10 mm apart. The ground is their triangulation, extended to the boundary; [] makes it '
			. 'flat at groundLevel again',
		'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 3, 'maxItems' => 3],
		'default' => null,
	];

	private const array ContoursSchema = [
		'type' => ['array', 'null'],
		'description' => 'contour lines: each line\'s points (plan mm) lie at its z; points are added along it every 2 m',
		'items' => [
			'type' => 'object',
			'properties' => [
				'z' => ['type' => 'integer', 'description' => 'mm against the floor\'s level'],
				'points' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2, 'maxItems' => 2]],
			],
			'required' => ['z', 'points'],
		],
		'default' => null,
	];

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param list<list<int>> $polygon the plot's boundary as [[x, y], …] in mm (plan coordinates of the floor), at least 3 points, not crossing itself
	 * @param string|null $name e.g. "Parcel 1234/5" (default "Plot")
	 * @param int|null $groundMaterialId the ground inside the boundary, from list_materials (default a lawn)
	 * @param int|null $groundLevel mm the ground lies above (+) or below (−) the floor's level, e.g. -300 when the ground floor stands on a plinth (default 0)
	 * @param list<list<int>>|null $terrain spot heights of a sloping ground
	 */
	#[McpTool(
		name: 'add_plot',
		description: 'Add a plot: the boundary of the land the building stands on, with its ground (a lawn by default). Paths, '
			. 'terraces and gardens on it are rooms; fences along the boundary are Fence items. A sloping ground has spot '
			. 'heights (terrain, or set_terrain later): gardens, paths and driveways on it follow it, items with onGround '
			. 'stand on it, and the house\'s rooms below it are cut out of it.',
	)]
	public function addPlot(
		int $projectId,
		int $floorId,
		array $polygon,
		?string $name = null,
		?int $groundMaterialId = null,
		?int $groundLevel = null,
		#[Schema(definition: self::TerrainSchema)]
		?array $terrain = null,
	): EditResultDto
	{
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Plot,
			ToolContext::fields([
				'polygon' => $polygon,
				'name' => $name,
				'groundMaterialId' => $groundMaterialId,
				'groundLevel' => $groundLevel,
				'terrain' => $terrain,
			]),
		);
	}

	/**
	 * @param list<list<int>>|null $polygon new boundary as [[x, y], …] in mm
	 * @param int|null $groundMaterialId the ground inside the boundary, from list_materials
	 * @param int|null $groundLevel mm the ground lies above (+) or below (−) the floor's level (where it has no spot heights)
	 * @param list<list<int>>|null $terrain replaces all spot heights
	 * @param list<string>|null $clear fields to set to none: "groundMaterialId" (a lawn again)
	 */
	#[McpTool(name: 'update_plot', description: 'Change a plot. Only the given fields change.')]
	public function updatePlot(
		int $projectId,
		int $floorId,
		int $plotId,
		?string $name = null,
		?array $polygon = null,
		?int $groundMaterialId = null,
		?int $groundLevel = null,
		#[Schema(definition: self::TerrainSchema)]
		?array $terrain = null,
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Plot,
			$plotId,
			ToolContext::fields(
				['name' => $name, 'polygon' => $polygon, 'groundMaterialId' => $groundMaterialId, 'groundLevel' => $groundLevel, 'terrain' => $terrain],
				$clear,
				self::Clearable,
			),
		);
	}

	/**
	 * @param list<list<int>>|null $points spot heights [[x, y, z], …]
	 * @param list<array<string, mixed>>|null $contours contour lines
	 */
	#[McpTool(
		name: 'set_terrain',
		description: 'Replace the spot heights of a plot\'s sloping ground with points and/or contour lines read off a site '
			. 'plan or survey (z in mm against the floor\'s level). Without either, the ground is flat at its groundLevel again.',
	)]
	public function setTerrain(
		int $projectId,
		int $floorId,
		int $plotId,
		#[Schema(definition: self::TerrainSchema)]
		?array $points = null,
		#[Schema(definition: self::ContoursSchema)]
		?array $contours = null,
	): EditResultDto {
		// Checked here to read the points before the contours are sampled; contour points closer than 10 mm to a spot
		// height are dropped.
		$spots = SpotHeights::validate($points ?? [], PlotHandler::CoordinateLimit, PlotHandler::MaxGroundLevel);

		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Plot,
			$plotId,
			['terrain' => ContourLines::withContours($spots, ContourLines::validate($contours ?? []))],
		);
	}

	#[McpTool(name: 'delete_plot', description: 'Delete a plot (can be undone).', annotations: new ToolAnnotations(destructiveHint: true))]
	public function deletePlot(int $projectId, int $floorId, int $plotId): EditResultDto
	{
		return $this->editor->delete($this->context->user(), $this->context->floor($projectId, $floorId), ElementTypeEnum::Plot, $plotId);
	}
}
