<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\SavedViewDto;
use FlatDesigner\Dto\SavedViewInputDto;
use FlatDesigner\Dto\SavedViewSettingsDto;
use FlatDesigner\Dto\SunTimeDto;
use FlatDesigner\Model\Entity\SavedView;
use FlatDesigner\Service\Project\SavedViewProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use RuntimeException;

/**
 * Saved views: named views of a floor (camera, display, variants), applied in the viewer with a click and captured by
 * the screenshot script. They are not floor elements, so they bump no revision and have no undo.
 */
final readonly class ViewTools
{
	private const array CameraSchema = [
		'type' => ['object', 'null'],
		'description' => 'the 3D camera, plan mm (z = height above the floor): position and the point it looks at, e.g. an '
			. 'eye-level shot {"position": {"x": 1200, "y": 900, "z": 1630}, "target": {"x": 4200, "y": 3500, "z": 1300}}; '
			. 'null = the default framing of the floor',
		'properties' => ['position' => ShareLinkTools::PointSchema, 'target' => ShareLinkTools::PointSchema],
		'required' => ['position', 'target'],
		'default' => null,
	];

	private const array VariantsSchema = [
		'type' => ['object', 'null'],
		'description' => 'the variant to show per variant set of the floor: {"<setId>": groupId} (from get_floor); sets left out show their active variant',
		'additionalProperties' => ['type' => 'integer'],
		'default' => null,
	];

	public function __construct(private ToolContext $context, private SavedViewProvider $viewProvider)
	{
	}

	/** @return list<SavedViewDto> */
	#[McpTool(
		name: 'list_views',
		description: 'List the saved views of a project (by name) with their floor, view mode, camera and display.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listViews(int $projectId): array
	{
		return array_map(
			fn (SavedView $view): SavedViewDto => $this->viewProvider->toDto($view),
			$this->viewProvider->list($this->context->project($projectId)),
		);
	}

	/**
	 * @param int $floorId floor of the working copy
	 * @param string $name the view's name (at most 100 characters); a view of that name is replaced
	 * @param string $view "overview" (3D) or "top" (plan)
	 * @param array<string, mixed>|null $camera the 3D camera (see the schema)
	 * @param bool $ceilings show ceilings
	 * @param bool $labels show room labels
	 * @param int|null $sectionHeight wall cut, mm above the floor (900–2700); null = none
	 * @param bool $dimensions dimension lines (plan view)
	 * @param bool $night after dark: no daylight
	 * @param bool|null $lightsOn the flat's lights; null = on at night, off by day
	 * @param int|null $sunMonth with `sunHour`: the sun on the 21st of this month (1–12), from the project's location and north
	 *   angle (update_project); null = the default afternoon sun
	 * @param float|null $sunHour clock time on site of the sun, 0–24 (e.g. 15.5 = 15:30)
	 * @param bool $presentation presentation quality (ambient occlusion, antialiasing), as for screenshots
	 * @param bool $allFloors draw the floors under this one below it
	 * @param array<string, int>|null $variants the variant per variant set (see the schema)
	 */
	#[McpTool(
		name: 'save_view',
		description: 'Save a named view of a floor (camera, display options, variants) to come back to, e.g. for screenshots. '
			. 'A view of the same name is replaced.',
	)]
	public function saveView(
		int $projectId,
		int $floorId,
		string $name,
		string $view = 'overview',
		#[Schema(definition: self::CameraSchema)]
		?array $camera = null,
		bool $ceilings = false,
		bool $labels = true,
		?int $sectionHeight = null,
		bool $dimensions = false,
		bool $night = false,
		?bool $lightsOn = null,
		?int $sunMonth = null,
		?float $sunHour = null,
		bool $presentation = true,
		bool $allFloors = false,
		#[Schema(definition: self::VariantsSchema)]
		?array $variants = null,
	): SavedViewDto {
		$project = $this->context->project($projectId);
		$input = new SavedViewInputDto($floorId, $name, SavedViewSettingsDto::fromArray([
			'view' => $view,
			'camera' => $camera,
			'ceilings' => $ceilings,
			'labels' => $labels,
			'sectionHeight' => $sectionHeight,
			'dimensions' => $dimensions,
			'night' => $night,
			'lightsOn' => $lightsOn,
			'sun' => SunTimeDto::fields($sunMonth, $sunHour),
			'presentation' => $presentation,
			'allFloors' => $allFloors,
			'variants' => $variants,
		]));
		$existing = $this->viewProvider->findByName($project, $name);
		$saved = $existing === null
			? $this->viewProvider->create($this->context->user(), $project, $input)
			: $this->viewProvider->update($existing, $input);

		return $this->viewProvider->toDto($saved);
	}

	#[McpTool(name: 'delete_view', description: 'Delete a saved view.', annotations: new ToolAnnotations(destructiveHint: true))]
	public function deleteView(int $projectId, int $viewId): SavedViewDto
	{
		$view = $this->viewProvider->find($this->context->project($projectId), $viewId)
			?? throw new RuntimeException(sprintf('View %d is not a view of project %d. Use list_views.', $viewId, $projectId));
		$dto = $this->viewProvider->toDto($view);
		$this->viewProvider->delete($view);

		return $dto;
	}
}
