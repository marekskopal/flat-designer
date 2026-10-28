<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * The ground of a plot from its spot heights (FD-86): their Delaunay triangulation, extended to the plot's boundary.
 * Each boundary corner takes the height the spot heights give it (inside their triangles, else at the nearest point of
 * their outline), and the spot heights and corners are triangulated together, so the surface covers the whole plot and
 * is planar in each triangle. Outside its triangles the ground has the height of the nearest point of their outline.
 * Without spot heights the ground is flat at the plot's level.
 *
 * The twin is `viewer/engine/terrain.ts`, kept identical by `tests/fixtures/terrain.json`: both run the same
 * arithmetic in the same order, so they triangulate co-circular points (a regular grid) the same way.
 */
final readonly class Terrain
{
	/** A boundary corner this close to a spot height (mm) is that spot height. */
	private const float SamePoint = 1.0;

	/** Barycentric tolerance: a point on a triangle's edge lies in it. */
	private const float Inside = -1e-9;

	/**
	 * @param list<array{float, float, float}> $vertices x, y, z
	 * @param list<array{int, int, int}> $triangles counter-clockwise vertex indices
	 */
	private function __construct(public array $vertices, public array $triangles, private float $level)
	{
	}

	/**
	 * @param list<array{int, int, int}> $points spot heights [x, y, z], mm (z against the floor's level)
	 * @param list<array{int, int}> $polygon the plot's boundary
	 */
	public static function of(array $points, array $polygon, int $groundLevel): self
	{
		if ($points === []) {
			return new self([], [], $groundLevel);
		}
		$spots = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1], (float) $p[2]], $points);
		$heights = new self($spots, Delaunay::triangulate($spots), $groundLevel);
		$vertices = $spots;
		foreach ($polygon as [$x, $y]) {
			[$x, $y] = [(float) $x, (float) $y];
			if (!self::near($spots, $x, $y)) {
				$vertices[] = [$x, $y, $heights->at($x, $y)];
			}
		}

		return new self($vertices, Delaunay::triangulate($vertices), $groundLevel);
	}

	/** The ground's height at a plan point, mm against the floor's level. */
	public function at(float $x, float $y): float
	{
		if ($this->vertices === []) {
			return $this->level;
		}
		foreach ($this->triangles as [$i, $j, $k]) {
			[$ax, $ay, $az] = $this->vertices[$i];
			[$bx, $by, $bz] = $this->vertices[$j];
			[$cx, $cy, $cz] = $this->vertices[$k];
			$det = ($by - $cy) * ($ax - $cx) + ($cx - $bx) * ($ay - $cy);
			if ($det === 0.0) {
				continue;
			}
			$l1 = (($by - $cy) * ($x - $cx) + ($cx - $bx) * ($y - $cy)) / $det;
			$l2 = (($cy - $ay) * ($x - $cx) + ($ax - $cx) * ($y - $cy)) / $det;
			$l3 = 1 - $l1 - $l2;
			if ($l1 >= self::Inside && $l2 >= self::Inside && $l3 >= self::Inside) {
				return $l1 * $az + $l2 * $bz + $l3 * $cz;
			}
		}

		return $this->nearestOnOutline($x, $y);
	}

	/**
	 * The lowest and highest ground, mm.
	 *
	 * @return array{float, float}
	 */
	public function range(): array
	{
		if ($this->vertices === []) {
			return [$this->level, $this->level];
		}
		$zs = array_map(static fn (array $v): float => $v[2], $this->vertices);

		return [min($zs), max($zs)];
	}

	/** The height of the nearest point of `outline()`. */
	private function nearestOnOutline(float $x, float $y): float
	{
		$best = null;
		$height = 0.0;
		foreach ($this->outline() as [$a, $b]) {
			[$ax, $ay, $az] = $this->vertices[$a];
			[$bx, $by, $bz] = $this->vertices[$b];
			$dx = $bx - $ax;
			$dy = $by - $ay;
			$length = $dx * $dx + $dy * $dy;
			$t = $length === 0.0 ? 0.0 : max(0.0, min(1.0, (($x - $ax) * $dx + ($y - $ay) * $dy) / $length));
			$ex = $ax + $t * $dx - $x;
			$ey = $ay + $t * $dy - $y;
			$distance = $ex * $ex + $ey * $ey;
			if ($best !== null && $distance >= $best) {
				continue;
			}
			$best = $distance;
			$height = $az + $t * ($bz - $az);
		}

		return $height;
	}

	/**
	 * The triangles' outline: the edges of one triangle only, in the order of the triangles. Without triangles the
	 * points joined in the order of x, then y (collinear spot heights: a line), or the single point.
	 *
	 * @return list<array{int, int}>
	 */
	private function outline(): array
	{
		if ($this->triangles === []) {
			$order = array_keys($this->vertices);
			usort(
				$order,
				fn (int $a, int $b): int => [$this->vertices[$a][0], $this->vertices[$a][1], $a] <=> [$this->vertices[$b][0], $this->vertices[$b][1], $b],
			);
			$edges = [[$order[0], $order[0]]];
			for ($i = 1; $i < count($order); $i++) {
				$edges[] = [$order[$i - 1], $order[$i]];
			}

			return $edges;
		}
		$all = [];
		foreach ($this->triangles as [$i, $j, $k]) {
			$all[$i . ',' . $j] = true;
			$all[$j . ',' . $k] = true;
			$all[$k . ',' . $i] = true;
		}
		$edges = [];
		foreach ($this->triangles as $t) {
			foreach ([[$t[0], $t[1]], [$t[1], $t[2]], [$t[2], $t[0]]] as [$a, $b]) {
				if (!isset($all[$b . ',' . $a])) {
					$edges[] = [$a, $b];
				}
			}
		}

		return $edges;
	}

	/** @param list<array{float, float, float}> $points */
	private static function near(array $points, float $x, float $y): bool
	{
		foreach ($points as $p) {
			if (abs($p[0] - $x) <= self::SamePoint && abs($p[1] - $y) <= self::SamePoint) {
				return true;
			}
		}

		return false;
	}
}
