<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Service\Editor\SceneEditor;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** Plots: the boundary of the land around the building and its ground. */
final readonly class PlotTools
{
	private const array Clearable = ['groundMaterialId'];

	public function __construct(private ToolContext $context, private SceneEditor $editor)
	{
	}

	/**
	 * @param list<list<int>> $polygon the plot's boundary as [[x, y], …] in mm (plan coordinates of the floor), at least 3 points, not crossing itself
	 * @param string|null $name e.g. "Parcel 1234/5" (default "Plot")
	 * @param int|null $groundMaterialId the ground inside the boundary, from list_materials (default a lawn)
	 */
	#[McpTool(
		name: 'add_plot',
		description: 'Add a plot: the boundary of the land the building stands on, with its ground (a lawn by default). Paths, '
			. 'terraces and gardens on it are rooms; fences along the boundary are Fence items.',
	)]
	public function addPlot(
		int $projectId,
		int $floorId,
		array $polygon,
		?string $name = null,
		?int $groundMaterialId = null,
	): EditResultDto
	{
		return $this->editor->create(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Plot,
			ToolContext::fields(['polygon' => $polygon, 'name' => $name, 'groundMaterialId' => $groundMaterialId]),
		);
	}

	/**
	 * @param list<list<int>>|null $polygon new boundary as [[x, y], …] in mm
	 * @param int|null $groundMaterialId the ground inside the boundary, from list_materials
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
		?array $clear = null,
	): EditResultDto {
		return $this->editor->update(
			$this->context->user(),
			$this->context->floor($projectId, $floorId),
			ElementTypeEnum::Plot,
			$plotId,
			ToolContext::fields(['name' => $name, 'polygon' => $polygon, 'groundMaterialId' => $groundMaterialId], $clear, self::Clearable),
		);
	}

	#[McpTool(name: 'delete_plot', description: 'Delete a plot (can be undone).', annotations: new ToolAnnotations(destructiveHint: true))]
	public function deletePlot(int $projectId, int $floorId, int $plotId): EditResultDto
	{
		return $this->editor->delete($this->context->user(), $this->context->floor($projectId, $floorId), ElementTypeEnum::Plot, $plotId);
	}
}
