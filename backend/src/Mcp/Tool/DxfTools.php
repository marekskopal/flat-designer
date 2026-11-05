<?php

declare(strict_types=1);

namespace Izbik\Mcp\Tool;

use Izbik\Dto\DxfImportInputDto;
use Izbik\Dto\DxfImportResultDto;
use Izbik\Dto\DxfSummaryDto;
use Izbik\Service\Dxf\DxfImporter;
use Izbik\Service\Dxf\DxfPlan;
use Mcp\Capability\Attribute\McpTool;
use function array_filter;

/** Walls and openings from a CAD floor plan (an ASCII DXF uploaded as a project file). */
final readonly class DxfTools
{
	public function __construct(private ToolContext $context, private DxfImporter $importer)
	{
	}

	#[McpTool(
		name: 'inspect_dxf',
		description: 'List the layers of a DXF file of the project (upload it first with upload_project_file, or '
			. 'request_file_upload for a larger one): what is drawn on each (lines, arcs, block inserts, hatches, other) and the '
			. 'role its name suggests (wall, opening). Also the units the drawing says it is in (null: not said, read as mm). '
			. 'Use it to choose the layers for import_dxf. Only ASCII DXF is read; DWG and binary DXF have to be saved as ASCII DXF.',
	)]
	public function inspectDxf(int $projectId, int $fileId): DxfSummaryDto
	{
		return $this->importer->summary($this->context->project($projectId), $fileId);
	}

	/**
	 * @param int $fileId the DXF (inspect_dxf)
	 * @param list<string> $wallLayers layers with the walls, drawn as two parallel lines (or rectangles, outlines, hatches) 50–700 mm apart
	 * @param list<string>|null $openingLayers layers with the doors (swing arcs, door blocks) and windows (glazing lines, window blocks)
	 * @param int|null $units the drawing's units when its header does not say or is wrong: 4 mm, 5 cm, 14 dm, 6 m, 1 inch, 2 foot
	 * @param float|null $rotation degrees counter-clockwise to turn the drawing about its origin (default 0)
	 * @param string|null $anchor "Walls" (default): the walls' south-west outer corner at the plan origin, then the offset; "Drawing": the drawing's own origin at the plan origin (keeps floors drawn in one file stacked)
	 * @param int|null $offsetX mm added after the anchor
	 * @param int|null $offsetY mm added after the anchor
	 * @param int|null $wallHeight mm for every wall (default 2600)
	 * @param int|null $doorHeight mm for doors and passages (default per type)
	 * @param int|null $windowSill mm (default 900)
	 * @param int|null $windowHeight mm (default 1200)
	 * @param bool|null $replaceExisting true deletes the floor's walls (and their openings) in the same change
	 * @param bool|null $dryRun true writes nothing and returns the walls and openings it would make
	 */
	#[McpTool(
		name: 'import_dxf',
		description: 'Import the walls and openings of a DXF floor plan into a floor, as one change (one undo step). Two parallel '
			. 'lines of the wall layers a wall\'s thickness apart make a wall of that thickness (all Interior: retype the outer ones '
			. 'with update_wall); a gap between two pieces of a wall is an opening, a door where a swing arc is drawn in it '
			. '(hinge and swing side from the arc), a window where glazing lines are, else a passage; doors and windows drawn over '
			. 'an unbroken wall are found from the opening layers too. A plan has no heights: they come from the arguments. '
			. 'Use dryRun first and check the walls against the drawing (or an underlay image); `unpaired` are wall-layer lines that '
			. 'made no wall. Openings the editor refuses are left out and listed in `warnings`. Rooms are not imported: use '
			. 'detect_room afterwards.',
	)]
	public function importDxf(
		int $projectId,
		int $floorId,
		int $fileId,
		array $wallLayers,
		?array $openingLayers = null,
		?int $units = null,
		?float $rotation = null,
		?string $anchor = null,
		?int $offsetX = null,
		?int $offsetY = null,
		?int $wallHeight = null,
		?int $doorHeight = null,
		?int $windowSill = null,
		?int $windowHeight = null,
		?bool $replaceExisting = null,
		?bool $dryRun = null,
	): DxfPlan|DxfImportResultDto {
		$floor = $this->context->floor($projectId, $floorId);
		$input = DxfImportInputDto::fromArray(array_filter([
			'fileId' => $fileId,
			'wallLayers' => $wallLayers,
			'openingLayers' => $openingLayers,
			'units' => $units,
			'rotation' => $rotation,
			'anchor' => $anchor,
			'offsetX' => $offsetX,
			'offsetY' => $offsetY,
			'wallHeight' => $wallHeight,
			'doorHeight' => $doorHeight,
			'windowSill' => $windowSill,
			'windowHeight' => $windowHeight,
			'replaceExisting' => $replaceExisting,
		], static fn (mixed $value): bool => $value !== null));

		if ($dryRun === true) {
			return $this->importer->preview($floor->project, $input);
		}

		return $this->importer->import($this->context->user(), $floor, $input);
	}
}
