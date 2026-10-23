<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * Where lines (a point and a unit direction) and circles meet, for the mitres of curved walls (`WallJoints`).
 *
 * @phpstan-import-type Point from PlanMath
 */
final readonly class Intersections
{
	/**
	 * @param Point $p
	 * @param Point $d
	 * @param Point $q
	 * @param Point $e
	 * @return list<Point>
	 */
	public static function lineLine(array $p, array $d, array $q, array $e): array
	{
		$cross = $d[0] * $e[1] - $d[1] * $e[0];
		if (abs($cross) < 1e-9) {
			return [];
		}
		$t = (($q[0] - $p[0]) * $e[1] - ($q[1] - $p[1]) * $e[0]) / $cross;

		return [[$p[0] + $d[0] * $t, $p[1] + $d[1] * $t]];
	}

	/**
	 * @param Point $p
	 * @param Point $d
	 * @param Point $centre
	 * @return list<Point>
	 */
	public static function lineCircle(array $p, array $d, array $centre, float $radius): array
	{
		$fx = $p[0] - $centre[0];
		$fy = $p[1] - $centre[1];
		$b = $fx * $d[0] + $fy * $d[1];
		$discriminant = $b * $b - ($fx * $fx + $fy * $fy - $radius * $radius);
		if ($discriminant < 0) {
			return [];
		}
		$root = sqrt($discriminant);

		return array_map(static fn (float $t): array => [$p[0] + $d[0] * $t, $p[1] + $d[1] * $t], [-$b - $root, -$b + $root]);
	}

	/**
	 * @param Point $c1
	 * @param Point $c2
	 * @return list<Point>
	 */
	public static function circleCircle(array $c1, float $r1, array $c2, float $r2): array
	{
		$dx = $c2[0] - $c1[0];
		$dy = $c2[1] - $c1[1];
		$distance = hypot($dx, $dy);
		if ($distance === 0.0 || $distance > $r1 + $r2 || $distance < abs($r1 - $r2)) {
			return [];
		}
		$along = ($r1 * $r1 - $r2 * $r2 + $distance * $distance) / (2 * $distance);
		$height = sqrt(max($r1 * $r1 - $along * $along, 0.0));
		$bx = $c1[0] + $dx * $along / $distance;
		$by = $c1[1] + $dy * $along / $distance;

		return array_map(
			static fn (int $sign): array => [$bx - $sign * $dy * $height / $distance, $by + $sign * $dx * $height / $distance],
			[1, -1],
		);
	}
}
