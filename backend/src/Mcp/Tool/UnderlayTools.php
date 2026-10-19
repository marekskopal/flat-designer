<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\UnderlayDto;
use FlatDesigner\Dto\UnderlayInputDto;
use FlatDesigner\Service\Project\UnderlayService;
use Mcp\Capability\Attribute\McpTool;
use RuntimeException;

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
		$calibration = $points === null ? null : array_map(self::point(...), $points);

		return UnderlayDto::fromEntity($this->underlayService->set(
			$this->context->floor($projectId, $floorId),
			new UnderlayInputDto(
				fileId: $fileId,
				x: $x,
				y: $y,
				scale: $scale,
				rotation: $rotation,
				opacity: $opacity,
				points: $calibration,
			),
		));
	}

	/**
	 * A calibration point as `[px, py, x, y]`, or as the REST body's `{px, py, x, y}` (the schema check lets objects
	 * through as arrays).
	 *
	 * @return array{px: float, py: float, x: float, y: float}
	 */
	private static function point(mixed $point): array
	{
		$values = null;
		if (is_array($point) && array_is_list($point) && count($point) === 4) {
			$values = $point;
		} elseif (is_array($point) && isset($point['px'], $point['py'], $point['x'], $point['y'])) {
			$values = [$point['px'], $point['py'], $point['x'], $point['y']];
		}
		$numbers = [];
		foreach ($values ?? [] as $value) {
			if (!is_int($value) && !is_float($value)) {
				$numbers = [];
				break;
			}
			$numbers[] = (float) $value;
		}
		if (count($numbers) !== 4) {
			throw new RuntimeException('Each point must be [px, py, x, y] in numbers: an image pixel and the plan mm it belongs at.');
		}

		return ['px' => $numbers[0], 'py' => $numbers[1], 'x' => $numbers[2], 'y' => $numbers[3]];
	}

	/** @return array{removed: bool} */
	#[McpTool(name: 'remove_floor_underlay', description: 'Remove the plan image from under the floor\'s top view.')]
	public function removeFloorUnderlay(int $projectId, int $floorId): array
	{
		return ['removed' => $this->underlayService->remove($this->context->floor($projectId, $floorId))];
	}
}
