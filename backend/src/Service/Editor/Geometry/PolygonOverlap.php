<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * The area two simple polygons share (FD-90: a floor void over a room). The second polygon is split into triangles (ear
 * clipping), and the first is cut by each of them (`ConvexClip`, right for a concave first polygon too).
 *
 * @phpstan-import-type Point from PlanMath
 */
final readonly class PolygonOverlap
{
	/**
	 * mm² inside both.
	 *
	 * @param list<Point> $subject
	 * @param list<Point> $clip
	 */
	public static function area(array $subject, array $clip): float
	{
		$area = 0.0;
		foreach (self::triangles($clip) as [$a, $b, $c]) {
			$region = [self::left($a, $b), self::left($b, $c), self::left($c, $a)];
			$area += abs(PlanMath::signedArea(ConvexClip::within($subject, $region)));
		}

		return $area;
	}

	/**
	 * The polygon in counter-clockwise triangles.
	 *
	 * @param list<Point> $polygon
	 * @return list<array{Point, Point, Point}>
	 */
	public static function triangles(array $polygon): array
	{
		$points = PlanMath::signedArea($polygon) < 0 ? array_reverse($polygon) : $polygon;
		$triangles = [];
		while (count($points) > 3) {
			$ear = self::ear($points);
			if ($ear === null) {
				// Only collinear points left (or a polygon crossing itself): no area to share.
				return $triangles;
			}
			$count = count($points);
			$triangles[] = [$points[($ear + $count - 1) % $count], $points[$ear], $points[($ear + 1) % $count]];
			array_splice($points, $ear, 1);
		}
		if (count($points) === 3 && abs(PlanMath::signedArea($points)) > 0) {
			$triangles[] = [$points[0], $points[1], $points[2]];
		}

		return $triangles;
	}

	/**
	 * A vertex whose triangle with its neighbours turns left and holds no other vertex; null when none does.
	 *
	 * @param list<Point> $points counter-clockwise
	 */
	private static function ear(array $points): ?int
	{
		$count = count($points);
		for ($i = 0; $i < $count; $i++) {
			$a = $points[($i + $count - 1) % $count];
			$b = $points[$i];
			$c = $points[($i + 1) % $count];
			if (self::turn($a, $b, $c) <= 0) {
				continue;
			}
			$empty = true;
			foreach ($points as $j => $p) {
				if (
					$j !== $i
					&& $p !== $a
					&& $p !== $c
					&& self::turn($a, $b, $p) >= 0
					&& self::turn($b, $c, $p) >= 0
					&& self::turn($c, $a, $p) >= 0
				) {
					$empty = false;

					break;
				}
			}
			if ($empty) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * @param Point $a
	 * @param Point $b
	 * @param Point $c
	 */
	private static function turn(array $a, array $b, array $c): float
	{
		return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
	}

	/**
	 * The half-plane left of a → b, as `ConvexClip` takes it (a·x + b·y < c).
	 *
	 * @param Point $from
	 * @param Point $to
	 * @return array{float, float, float}
	 */
	private static function left(array $from, array $to): array
	{
		$a = $to[1] - $from[1];
		$b = $from[0] - $to[0];

		return [$a, $b, $a * $from[0] + $b * $from[1]];
	}
}
