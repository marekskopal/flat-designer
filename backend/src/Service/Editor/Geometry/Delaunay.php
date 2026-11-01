<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/** Delaunay triangulation of plan points for `Terrain`; the twin is `triangulate()` in `viewer/engine/terrain.ts`. */
final readonly class Delaunay
{
	/**
	 * Bowyer–Watson: the points inserted in their order into a large enclosing triangle. Counter-clockwise index
	 * triples of points [x, y, …]; collinear or repeated points add no triangles.
	 *
	 * @param list<array{float, float, float}> $points
	 * @return list<array{int, int, int}>
	 */
	public static function triangulate(array $points): array
	{
		$n = count($points);
		if ($n < 3) {
			return [];
		}
		$xs = array_map(static fn (array $p): float => $p[0], $points);
		$ys = array_map(static fn (array $p): float => $p[1], $points);
		$mx = (min($xs) + max($xs)) / 2;
		$my = (min($ys) + max($ys)) / 2;
		$d = max(max($xs) - min($xs), max($ys) - min($ys), 1.0) * 100;
		// Relative to the middle, which keeps the in-circle products small.
		$p = array_map(static fn (array $q): array => [$q[0] - $mx, $q[1] - $my], $points);
		$p[] = [-20 * $d, -$d];
		$p[] = [20 * $d, -$d];
		$p[] = [0.0, 20 * $d];
		$triangles = [[$n, $n + 1, $n + 2]];
		for ($i = 0; $i < $n; $i++) {
			$triangles = self::insert($triangles, $p, $i);
		}

		return array_values(array_filter($triangles, static fn (array $t): bool => max($t) < $n));
	}

	/**
	 * The triangles with point `i` inserted: those whose circumcircle holds it are replaced by a fan from it to their
	 * cavity's edges (appended after the kept ones).
	 *
	 * @param list<array{int, int, int}> $triangles
	 * @param list<array{float, float}> $p
	 * @return list<array{int, int, int}>
	 */
	private static function insert(array $triangles, array $p, int $i): array
	{
		$bad = [];
		$kept = [];
		foreach ($triangles as $t) {
			if (self::inCircle($p[$t[0]], $p[$t[1]], $p[$t[2]], $p[$i])) {
				$bad[] = $t;
			} else {
				$kept[] = $t;
			}
		}
		$edges = [];
		foreach ($bad as $t) {
			array_push($edges, [$t[0], $t[1]], [$t[1], $t[2]], [$t[2], $t[0]]);
		}
		$keys = array_flip(array_map(static fn (array $e): string => $e[0] . ',' . $e[1], $edges));
		foreach ($edges as [$a, $b]) {
			if (!isset($keys[$b . ',' . $a])) {
				$kept[] = [$a, $b, $i];
			}
		}

		return $kept;
	}

	/**
	 * Whether `d` lies strictly inside the circle through the counter-clockwise `a`, `b`, `c`.
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param array{float, float} $c
	 * @param array{float, float} $d
	 */
	private static function inCircle(array $a, array $b, array $c, array $d): bool
	{
		$adx = $a[0] - $d[0];
		$ady = $a[1] - $d[1];
		$bdx = $b[0] - $d[0];
		$bdy = $b[1] - $d[1];
		$cdx = $c[0] - $d[0];
		$cdy = $c[1] - $d[1];
		$det = ($adx * $adx + $ady * $ady) * ($bdx * $cdy - $cdx * $bdy)
			+ ($bdx * $bdx + $bdy * $bdy) * ($cdx * $ady - $adx * $cdy)
			+ ($cdx * $cdx + $cdy * $cdy) * ($adx * $bdy - $bdx * $ady);

		return $det > 0;
	}
}
