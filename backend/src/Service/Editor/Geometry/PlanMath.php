<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * Plan geometry on float points (mm) for `RoomDetector`, matching the helpers of the frontend twin.
 *
 * @phpstan-type Point array{float, float}
 */
final readonly class PlanMath
{
	/**
	 * Even-odd test, as `pointInPolygon()` in the viewer.
	 *
	 * @param list<Point> $polygon
	 * @param Point $point
	 */
	public static function contains(array $polygon, array $point): bool
	{
		$inside = false;
		$count = count($polygon);
		$j = $count - 1;
		for ($i = 0; $i < $count; $i++) {
			[$xi, $yi] = $polygon[$i];
			[$xj, $yj] = $polygon[$j];
			if ($yi > $point[1] !== $yj > $point[1] && $point[0] < ($xj - $xi) * ($point[1] - $yi) / ($yj - $yi) + $xi) {
				$inside = !$inside;
			}
			$j = $i;
		}

		return $inside;
	}

	/**
	 * Shoelace area, mm²; positive for counter-clockwise polygons.
	 *
	 * @param list<Point> $polygon
	 */
	public static function signedArea(array $polygon): float
	{
		$sum = 0.0;
		$count = count($polygon);
		foreach ($polygon as $i => [$x, $y]) {
			[$nextX, $nextY] = $polygon[($i + 1) % $count];
			$sum += $x * $nextY - $nextX * $y;
		}

		return $sum / 2;
	}

	/**
	 * Distance of the point from the line through a and b (from a when they coincide).
	 *
	 * @param Point $point
	 * @param Point $a
	 * @param Point $b
	 */
	public static function distanceToLine(array $point, array $a, array $b): float
	{
		$length = hypot($b[0] - $a[0], $b[1] - $a[1]);
		if ($length === 0.0) {
			return hypot($point[0] - $a[0], $point[1] - $a[1]);
		}

		return abs(self::cross([$b[0] - $a[0], $b[1] - $a[1]], [$point[0] - $a[0], $point[1] - $a[1]])) / $length;
	}

	/**
	 * Direction from one point to another, radians counter-clockwise from the x axis.
	 *
	 * @param Point $from
	 * @param Point $to
	 */
	public static function angle(array $from, array $to): float
	{
		return atan2($to[1] - $from[1], $to[0] - $from[0]);
	}

	/**
	 * @param Point $a
	 * @param Point $b
	 */
	public static function cross(array $a, array $b): float
	{
		return $a[0] * $b[1] - $a[1] * $b[0];
	}

	/** Rounds .5 up on both signs, like the frontend twin (`Math.round` and PHP's `round` differ for negatives). */
	public static function roundHalfUp(float $value): int
	{
		return (int) floor($value + 0.5);
	}
}
