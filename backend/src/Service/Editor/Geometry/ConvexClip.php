<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * Polygons cut by half-planes (Sutherland–Hodgman), for the roofs' measures (`RoofCover`). A polygon cut by a half-plane
 * keeps its area right even when it is concave (a room's outline), with at worst a bridge of no width along the cut, and
 * every vertex of the result is a point of the part. A half-plane [a, b, c] is the points where a·x + b·y < c.
 *
 * @phpstan-import-type Point from PlanMath
 * @phpstan-type HalfPlane array{float, float, float}
 */
final readonly class ConvexClip
{
	/** mm², below which a piece is none. */
	private const float NoArea = 1e-3;

	/** mm, below which an edge is none. */
	private const float NoLength = 1e-6;

	/**
	 * The part of `polygon` in the half-plane.
	 *
	 * @param list<Point> $polygon
	 * @param HalfPlane $half
	 * @return list<Point>
	 */
	public static function clip(array $polygon, array $half): array
	{
		[$a, $b, $c] = $half;
		$out = [];
		$count = count($polygon);
		for ($i = 0; $i < $count; $i++) {
			$p = $polygon[$i];
			$q = $polygon[($i + 1) % $count];
			$sp = $c - $a * $p[0] - $b * $p[1];
			$sq = $c - $a * $q[0] - $b * $q[1];
			if ($sp > 0) {
				$out[] = $p;
			}
			if ($sp > 0 === $sq > 0) {
				continue;
			}
			$t = $sp / ($sp - $sq);
			$out[] = [$p[0] + ($q[0] - $p[0]) * $t, $p[1] + ($q[1] - $p[1]) * $t];
		}

		return $out;
	}

	/**
	 * The part of `polygon` inside a convex region given as half-planes; empty when nothing of it is.
	 *
	 * @param list<Point> $polygon
	 * @param list<HalfPlane> $region
	 * @return list<Point>
	 */
	public static function within(array $polygon, array $region): array
	{
		foreach ($region as $half) {
			$polygon = self::clip($polygon, $half);
			if (count($polygon) < 3) {
				return [];
			}
		}

		return abs(PlanMath::signedArea($polygon)) < self::NoArea ? [] : $polygon;
	}

	/**
	 * Convex pieces without a convex region: each piece splits into the parts outside each of the region's half-planes in
	 * turn (inside those before it), so the parts do not overlap.
	 *
	 * @param list<list<Point>> $pieces
	 * @param list<HalfPlane> $region
	 * @return list<list<Point>>
	 */
	public static function minus(array $pieces, array $region): array
	{
		$out = [];
		foreach ($pieces as $piece) {
			$rest = $piece;
			foreach ($region as [$a, $b, $c]) {
				$outside = self::within($rest, [[-$a, -$b, -$c]]);
				if ($outside !== []) {
					$out[] = $outside;
				}
				$rest = self::within($rest, [[$a, $b, $c]]);
				if ($rest === []) {
					break;
				}
			}
		}

		return $out;
	}

	/**
	 * The inside of a counter-clockwise convex polygon as half-planes (left of each edge). Edges of no length (a cut
	 * repeats a corner) bound nothing and are left out.
	 *
	 * @param list<Point> $polygon
	 * @return list<HalfPlane>
	 */
	public static function inside(array $polygon): array
	{
		$halves = [];
		$count = count($polygon);
		for ($i = 0; $i < $count; $i++) {
			[$ax, $ay] = $polygon[$i];
			[$bx, $by] = $polygon[($i + 1) % $count];
			if (abs($bx - $ax) + abs($by - $ay) < self::NoLength) {
				continue;
			}
			$halves[] = [$by - $ay, $ax - $bx, ($by - $ay) * $ax + ($ax - $bx) * $ay];
		}

		return $halves;
	}

	/**
	 * Area (mm²) and first moments (mm³, ∫x and ∫y) of a counter-clockwise polygon: the integral of a linear function
	 * k + gx·x + gy·y over it is k·area + gx·mx + gy·my.
	 *
	 * @param list<Point> $polygon
	 * @return array{float, float, float}
	 */
	public static function moments(array $polygon): array
	{
		$area = 0.0;
		$mx = 0.0;
		$my = 0.0;
		$count = count($polygon);
		foreach ($polygon as $i => [$x, $y]) {
			[$nx, $ny] = $polygon[($i + 1) % $count];
			$cross = $x * $ny - $nx * $y;
			$area += $cross;
			$mx += ($x + $nx) * $cross;
			$my += ($y + $ny) * $cross;
		}

		return [$area / 2, $mx / 6, $my / 6];
	}
}
