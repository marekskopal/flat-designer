<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * Where along a line in plan a height under the roofs may bend or step (FD-38): where the line crosses a roof's edge,
 * where two planes meet (a ridge, a hip, two roofs) and where a plane reaches one of the given levels. Between two
 * breaks every such height is linear, so a sum over the line is exact with the ends of each stretch. Twin of
 * `roofBreaks()` (`viewer/engine/under-roof.ts`).
 *
 * @phpstan-import-type Point from PlanMath
 */
final readonly class RoofBreaks
{
	/**
	 * The breaks as 0–1 along p → q, ascending, with 0 and 1; breaks closer than a hundredth of a mm are one.
	 *
	 * @param list<RoofGeometry> $roofs
	 * @param Point $p
	 * @param Point $q
	 * @param list<float> $levels heights (mm above the floor's level)
	 * @param list<array{float, float}> $slopes heights sloping straight from p to q (a wall's sloping top, FD-87)
	 * @return list<float>
	 */
	public static function along(array $roofs, array $p, array $q, array $levels, array $slopes = []): array
	{
		$lines = array_map(static fn (float $level): array => [$level, 0.0], $levels);
		foreach ($slopes as [$atP, $atQ]) {
			$lines[] = [$atP, $atQ - $atP];
		}
		$ts = [0.0, 1.0];
		foreach ($roofs as $roof) {
			array_push($ts, ...self::edges($roof, $p, $q));
			array_push($lines, ...self::heights($roof, $p, $q));
		}
		array_push($ts, ...self::crossings($lines));
		$inside = array_values(array_filter($ts, static fn (float $t): bool => is_finite($t) && $t >= 0 && $t <= 1));
		sort($inside);
		$length = sqrt(($q[0] - $p[0]) ** 2 + ($q[1] - $p[1]) ** 2);
		$distinct = [];
		foreach ($inside as $i => $t) {
			if ($i === 0 || ($t - $inside[$i - 1]) * $length > 0.01 || $t === 1.0) {
				$distinct[] = $t;
			}
		}

		return $distinct;
	}

	/**
	 * Where the line crosses the roof's edges (its outline with the overhangs), 0–1 along it, outside that too.
	 *
	 * @param Point $p
	 * @param Point $q
	 * @return list<float>
	 */
	private static function edges(RoofGeometry $roof, array $p, array $q): array
	{
		$a = $roof->toLocal($p[0], $p[1]);
		$b = $roof->toLocal($q[0], $q[1]);
		$half = $roof->halfExtent();
		$ts = [];
		foreach ([[$a[0], $b[0], $half[0]], [$a[1], $b[1], $half[1]]] as [$from, $to, $edge]) {
			if ($to !== $from) {
				array_push($ts, ($edge - $from) / ($to - $from), (-$edge - $from) / ($to - $from));
			}
		}

		return $ts;
	}

	/**
	 * The roof's planes along the line: the underside at p and its rise to q.
	 *
	 * @param Point $p
	 * @param Point $q
	 * @return list<array{float, float}>
	 */
	private static function heights(RoofGeometry $roof, array $p, array $q): array
	{
		$lines = [];
		foreach ($roof->planes() as $plane) {
			[$gx, $gy] = $plane['gradient'];
			$at = $plane['height'] + $gx * ($p[0] - $roof->x) + $gy * ($p[1] - $roof->y);
			$atEnd = $plane['height'] + $gx * ($q[0] - $roof->x) + $gy * ($q[1] - $roof->y);
			$lines[] = [$at, $atEnd - $at];
		}

		return $lines;
	}

	/**
	 * Where two of the lines (a height at p and its rise) cross, 0–1 along the line, outside that too.
	 *
	 * @param list<array{float, float}> $lines
	 * @return list<float>
	 */
	private static function crossings(array $lines): array
	{
		$ts = [];
		$count = count($lines);
		for ($i = 0; $i < $count; $i++) {
			for ($j = $i + 1; $j < $count; $j++) {
				$slope = $lines[$i][1] - $lines[$j][1];
				if (abs($slope) > 1e-9) {
					$ts[] = ($lines[$j][0] - $lines[$i][0]) / $slope;
				}
			}
		}

		return $ts;
	}
}
