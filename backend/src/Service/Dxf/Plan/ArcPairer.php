<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Service\Dxf\DxfArc;
use function abs;
use function array_push;
use function ceil;
use function floor;
use function hypot;
use function max;
use function min;
use function usort;
use const M_PI;

/**
 * Pairs concentric arcs a wall's thickness apart into curved walls over the angle they share, cut into pieces of at
 * most a semicircle (as a wall's curve may be). Each arc pairs once, the thinnest pair first.
 */
final class ArcPairer
{
	private const float Tolerance = 2.0;

	private const float MinOverlap = 20.0;

	/** The grid the arcs' centres are looked up in, mm. */
	private const float Cell = 10.0;

	/** @var list<CurvedPiece> */
	public array $curved = [];

	/** @var list<DxfArc> the arcs that made no curved wall */
	public array $loose = [];

	/** @param list<DxfArc> $arcs */
	public function __construct(array $arcs)
	{
		$candidates = self::candidates($arcs);
		usort($candidates, static fn (array $x, array $y): int => $x[2] <=> $y[2]);
		$used = [];
		foreach ($candidates as [$i, $j, $thickness, [$start, $end]]) {
			if (isset($used[$i]) || isset($used[$j])) {
				continue;
			}
			$used[$i] = true;
			$used[$j] = true;
			$this->addCurve($arcs[$i], ($arcs[$i]->radius + $arcs[$j]->radius) / 2, $thickness, $start, $end);
		}
		foreach ($arcs as $i => $arc) {
			if (!isset($used[$i])) {
				$this->loose[] = $arc;
			}
		}
	}

	/**
	 * @param list<DxfArc> $arcs
	 * @return list<array{int, int, float, array{float, float}}> the two arcs, the thickness and the angles they share
	 */
	private static function candidates(array $arcs): array
	{
		// Concentric arcs share a grid cell of their centres, or a neighbouring one.
		$cells = [];
		foreach ($arcs as $i => $arc) {
			$cells[(int) floor($arc->cx / self::Cell)][(int) floor($arc->cy / self::Cell)][] = $i;
		}
		$candidates = [];
		foreach ($arcs as $i => $a) {
			foreach (self::near($cells, $a) as $j) {
				$candidate = $j > $i ? self::candidate($a, $arcs[$j]) : null;
				if ($candidate !== null) {
					$candidates[] = [$i, $j, ...$candidate];
				}
			}
		}

		return $candidates;
	}

	/**
	 * The arcs whose centres lie in the arc's cell of the grid or a neighbouring one.
	 *
	 * @param array<int, array<int, list<int>>> $cells
	 * @return list<int>
	 */
	private static function near(array $cells, DxfArc $arc): array
	{
		$x = (int) floor($arc->cx / self::Cell);
		$y = (int) floor($arc->cy / self::Cell);
		$near = [];
		for ($dx = -1; $dx <= 1; $dx++) {
			for ($dy = -1; $dy <= 1; $dy++) {
				array_push($near, ...$cells[$x + $dx][$y + $dy] ?? []);
			}
		}

		return $near;
	}

	/** @return array{float, array{float, float}}|null the thickness and the angles both arcs cover, when they make a wall */
	private static function candidate(DxfArc $a, DxfArc $b): ?array
	{
		$thickness = abs($a->radius - $b->radius);
		if (hypot($a->cx - $b->cx, $a->cy - $b->cy) > self::Tolerance
			|| $thickness < WallPairer::MinThickness
			|| $thickness > WallPairer::MaxThickness) {
			return null;
		}
		$overlap = self::angleOverlap($a, $b);

		return $overlap !== null && ($overlap[1] - $overlap[0]) * min($a->radius, $b->radius) >= self::MinOverlap
			? [$thickness, $overlap]
			: null;
	}

	private function addCurve(DxfArc $arc, float $radius, float $thickness, float $start, float $end): void
	{
		$pieces = max(1, (int) ceil(($end - $start) / M_PI - 1e-9));
		for ($k = 0; $k < $pieces; $k++) {
			$this->curved[] = new CurvedPiece(
				$arc->cx,
				$arc->cy,
				$radius,
				$start + ($end - $start) * $k / $pieces,
				$start + ($end - $start) * ($k + 1) / $pieces,
				$thickness,
			);
		}
	}

	/** @return array{float, float}|null the angles (radians) both arcs cover, in `$a`'s turn */
	private static function angleOverlap(DxfArc $a, DxfArc $b): ?array
	{
		$best = null;
		foreach ([-2 * M_PI, 0.0, 2 * M_PI] as $shift) {
			$from = max($a->start, $b->start + $shift);
			$to = min($a->end, $b->end + $shift);
			if ($to > $from && ($best === null || $to - $from > $best[1] - $best[0])) {
				$best = [$from, $to];
			}
		}

		return $best;
	}
}
