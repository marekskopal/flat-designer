<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\UnderlayDto;
use FlatDesigner\Dto\UnderlayInputDto;
use FlatDesigner\Service\Project\UnderlayService;
use Mcp\Capability\Attribute\McpTool;

/** The plan image under a floor's top view, which the user traces over in the editor and an agent can calibrate. */
final readonly class UnderlayTools
{
	public function __construct(private ToolContext $context, private UnderlayService $underlayService)
	{
	}

	/**
	 * @param int $fileId an image file of the project (list_project_files)
	 * @param list<list<int|float>>|null $points calibration: two [px, py, x, y] lists, an image pixel (py counted down from the top) and the plan point in mm where it belongs; sets x, y, scale and rotation
	 * @param int|null $x plan x (mm) of the image's top-left corner
	 * @param int|null $y plan y (mm) of the image's top-left corner
	 * @param float|null $scale mm per image pixel
	 * @param float|null $rotation degrees counter-clockwise
	 * @param float|null $opacity 0.05–1 (default 0.5)
	 */
	#[McpTool(
		name: 'set_floor_underlay',
		description: 'Lay an image file of the project (a floor plan) under the floor\'s top view, so the user can trace over it '
			. 'and check walls against it. Calibrate it with two points whose image pixels and plan positions you know (e.g. '
			. 'the ends of a dimensioned wall or of the scale bar, read with get_project_file and grid), or give x, y, scale '
			. 'and rotation. Fields left out keep their value. Pixel (px, py) then lies at plan x = x + scale·(px·cos r + '
			. 'py·sin r), y = y + scale·(px·sin r − py·cos r). Not an undoable change; it does not change the design.',
	)]
	public function setFloorUnderlay(
		int $projectId,
		int $floorId,
		int $fileId,
		?array $points = null,
		?int $x = null,
		?int $y = null,
		?float $scale = null,
		?float $rotation = null,
		?float $opacity = null,
	): UnderlayDto {
		// Validated like the REST body, so both paths agree; a [px, py, x, y] list becomes the body's object.
		$input = UnderlayInputDto::fromArray([
			'fileId' => $fileId,
			'x' => $x,
			'y' => $y,
			'scale' => $scale,
			'rotation' => $rotation,
			'opacity' => $opacity,
			'points' => $points === null ? null : array_map(self::pointObject(...), $points),
		]);

		return UnderlayDto::fromEntity($this->underlayService->set($this->context->floor($projectId, $floorId), $input));
	}

	/**
	 * A point as `[px, py, x, y]` turned into `{px, py, x, y}`; anything else (the body's objects, which the schema
	 * check lets through, or a wrong shape) is left to the body reader's own messages.
	 */
	private static function pointObject(mixed $point): mixed
	{
		if (is_array($point) && array_is_list($point) && count($point) === 4) {
			return ['px' => $point[0], 'py' => $point[1], 'x' => $point[2], 'y' => $point[3]];
		}

		return $point;
	}

	/** @return array{removed: bool} */
	#[McpTool(name: 'remove_floor_underlay', description: 'Remove the plan image from under the floor\'s top view.')]
	public function removeFloorUnderlay(int $projectId, int $floorId): array
	{
		return ['removed' => $this->underlayService->remove($this->context->floor($projectId, $floorId))];
	}
}
