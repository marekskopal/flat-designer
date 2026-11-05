<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Service\Dxf\DxfAnchorEnum;
use Izbik\Service\Dxf\DxfPlanOptions;
use function cos;
use function deg2rad;
use function min;
use function round;
use function sin;

/**
 * How the drawing lies on the plan: turned about its origin, then moved so its origin (or the walls' south-west
 * corner, the lowest x and y of their footprints) lands at the plan origin, then by the offset.
 */
final readonly class Placement
{
	private float $cos;

	private float $sin;

	private float $moveX;

	private float $moveY;

	/**
	 * @param list<WallPiece> $walls
	 * @param list<CurvedPiece> $curved
	 */
	public function __construct(DxfPlanOptions $options, array $walls, array $curved)
	{
		$this->cos = cos(deg2rad($options->rotation));
		$this->sin = sin(deg2rad($options->rotation));
		[$minX, $minY] = $options->anchor === DxfAnchorEnum::Walls ? $this->lowest($walls, $curved) : [0.0, 0.0];
		$this->moveX = $options->offsetX - $minX;
		$this->moveY = $options->offsetY - $minY;
	}

	/** @return array{float, float} */
	public function place(float $x, float $y): array
	{
		[$tx, $ty] = $this->turn($x, $y);

		return [$tx + $this->moveX, $ty + $this->moveY];
	}

	/** @return array{int, int} */
	public function round(float $x, float $y): array
	{
		[$px, $py] = $this->place($x, $y);

		return [(int) round($px), (int) round($py)];
	}

	/** @return array{float, float} */
	private function turn(float $x, float $y): array
	{
		return [$x * $this->cos - $y * $this->sin, $x * $this->sin + $y * $this->cos];
	}

	/**
	 * @param list<WallPiece> $walls
	 * @param list<CurvedPiece> $curved
	 * @return array{float, float}
	 */
	private function lowest(array $walls, array $curved): array
	{
		$points = [];
		foreach ($walls as $wall) {
			foreach ([$wall->t0, $wall->t1] as $t) {
				$points[] = $wall->frame->point($t, $wall->c - $wall->thickness / 2);
				$points[] = $wall->frame->point($t, $wall->c + $wall->thickness / 2);
			}
		}
		foreach ($curved as $curve) {
			foreach ([$curve->start, ($curve->start + $curve->end) / 2, $curve->end] as $angle) {
				foreach ([-1, 1] as $side) {
					$radius = $curve->radius + $side * $curve->thickness / 2;
					$points[] = [$curve->cx + $radius * cos($angle), $curve->cy + $radius * sin($angle)];
				}
			}
		}
		if ($points === []) {
			return [0.0, 0.0];
		}
		[$minX, $minY] = $this->turn(...$points[0]);
		foreach ($points as [$x, $y]) {
			[$tx, $ty] = $this->turn($x, $y);
			$minX = min($minX, $tx);
			$minY = min($minY, $ty);
		}

		return [$minX, $minY];
	}
}
