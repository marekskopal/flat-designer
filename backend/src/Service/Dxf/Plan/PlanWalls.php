<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Service\Dxf\DxfPlan;
use Izbik\Service\Editor\Element\WallHandler;
use function cos;
use function count;
use function floor;
use function hypot;
use function max;
use function min;
use function round;
use function sin;
use function sprintf;

/**
 * The walls and openings found, placed on the plan in whole millimetres (shared corners round alike, being one
 * point), as the editor takes them: start, end, sagitta (+ to the left of start → end) and thickness; an opening's
 * offset from its wall's start. Walls shorter than the editor allows are left out with their openings.
 *
 * @phpstan-import-type PlanWall from DxfPlan
 * @phpstan-import-type PlanOpening from DxfPlan
 */
final class PlanWalls
{
	/** @var list<PlanWall> */
	public array $walls = [];

	/** @var list<PlanOpening> */
	public array $openings = [];

	/** @var list<string> */
	public array $warnings = [];

	/**
	 * @param list<WallPiece> $walls
	 * @param list<CurvedPiece> $curved
	 */
	public function __construct(private readonly Placement $placement, array $walls, array $curved)
	{
		$short = 0;
		foreach ($walls as $wall) {
			$short += $this->add($this->state($wall->start(), $wall->end(), 0.0, $wall->thickness), $wall) ? 0 : 1;
		}
		foreach ($curved as $curve) {
			$state = $this->state(
				[$curve->cx + $curve->radius * cos($curve->start), $curve->cy + $curve->radius * sin($curve->start)],
				[$curve->cx + $curve->radius * cos($curve->end), $curve->cy + $curve->radius * sin($curve->end)],
				// Counter-clockwise round the centre, the arc bulges to the right of start → end.
				-$curve->radius * (1 - cos(($curve->end - $curve->start) / 2)),
				$curve->thickness,
			);
			$short += $this->add($state, null) ? 0 : 1;
		}
		if ($short > 0) {
			$this->warnings[] = sprintf('%d piece(s) of wall shorter than %d mm were left out.', $short, WallHandler::MinLength);
		}
		if ($this->walls === []) {
			$this->warnings[] = 'No walls were found on the chosen layers: walls are read from two parallel lines 50 to 700 mm apart.';
		}
	}

	/**
	 * @param array{float, float} $start
	 * @param array{float, float} $end
	 * @return PlanWall
	 */
	private function state(array $start, array $end, float $sagitta, float $thickness): array
	{
		[$startX, $startY] = $this->placement->round(...$start);
		[$endX, $endY] = $this->placement->round(...$end);

		return [
			'startX' => $startX,
			'startY' => $startY,
			'endX' => $endX,
			'endY' => $endY,
			'sagitta' => (int) round($sagitta),
			'thickness' => max(10, (int) round($thickness)),
		];
	}

	/** @param PlanWall $state */
	private function add(array $state, ?WallPiece $wall): bool
	{
		$chord = hypot($state['endX'] - $state['startX'], $state['endY'] - $state['startY']);
		if ($chord < WallHandler::MinLength) {
			return false;
		}
		$index = count($this->walls);
		$this->walls[] = $state;
		if ($wall !== null) {
			$this->addOpenings($wall, $index, $chord);
		}

		return true;
	}

	private function addOpenings(WallPiece $wall, int $index, float $chord): void
	{
		foreach ($wall->openings as $opening) {
			$offset = max(0, (int) round($opening->from - $wall->t0));
			$width = min((int) round($opening->to - $opening->from), (int) floor($chord) - $offset);
			if ($width < 100) {
				continue;
			}

			$this->openings[] = [
				'wall' => $index,
				'type' => $opening->type->value,
				'offset' => $offset,
				'width' => $width,
				'leafWidth' => $opening->leafWidth === null ? null : min($width, (int) round($opening->leafWidth)),
				'hinge' => $opening->hinge?->value,
				'side' => $opening->side?->value,
			];
		}
	}
}
