<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * The planar graph of wall footprint edges for `RoomDetector`: every segment is split where another one
 * crosses it or ends on it, points within `Tolerance` are merged (the first one seen stays), duplicate
 * edges are dropped, and vertices with a single edge are pruned until none is left.
 *
 * @phpstan-import-type Point from PlanMath
 */
final class FaceGraph
{
	/** Points closer than this (mm) are one vertex. */
	private const float Tolerance = 1.0;

	/** @var list<Point> */
	private array $vertices = [];

	/** @var array<string, true> "u:v" with u < v, in the order found */
	private array $edges = [];

	/** @var list<list<int>> */
	private array $neighbours = [];

	/** @param list<array{Point, Point}> $segments */
	public function __construct(array $segments)
	{
		foreach ($segments as $i => $segment) {
			$this->addSegment($segments, $i, $segment);
		}

		$this->neighbours = array_fill(0, count($this->vertices), []);
		foreach (array_keys($this->edges) as $key) {
			[$u, $v] = array_map(intval(...), explode(':', $key));
			$this->neighbours[$u][] = $v;
			$this->neighbours[$v][] = $u;
		}
		$this->prune();
	}

	/**
	 * Faces of the graph: from each directed edge u → v, turn onto the edge at v that comes next clockwise
	 * after v → u. Bounded faces come out counter-clockwise (positive area), the outer ones clockwise.
	 *
	 * @return list<list<Point>>
	 */
	public function faces(): array
	{
		$sorted = [];
		foreach ($this->neighbours as $u => $list) {
			usort(
				$list,
				fn (int $p, int $q): int => PlanMath::angle($this->vertices[$u], $this->vertices[$p])
					<=> PlanMath::angle($this->vertices[$u], $this->vertices[$q]),
			);
			$sorted[$u] = $list;
		}

		$visited = [];
		$faces = [];
		foreach ($sorted as $start => $list) {
			foreach ($list as $first) {
				if (isset($visited[sprintf('%d:%d', $start, $first)])) {
					continue;
				}
				$faces[] = $this->traceFace($sorted, $visited, $start, $first);
			}
		}

		return $faces;
	}

	/**
	 * @param array<int, list<int>> $sorted neighbours of each vertex by angle
	 * @param array<string, true> $visited directed edges walked so far
	 * @return list<Point>
	 */
	private function traceFace(array $sorted, array &$visited, int $u, int $v): array
	{
		$face = [];
		$limit = count($this->vertices) * 2;
		for ($steps = 0; $steps <= $limit && !isset($visited[sprintf('%d:%d', $u, $v)]); $steps++) {
			$visited[sprintf('%d:%d', $u, $v)] = true;
			$face[] = $this->vertices[$u];
			$around = $sorted[$v];
			$index = (int) array_search($u, $around, true);
			$next = $around[($index - 1 + count($around)) % count($around)];
			$u = $v;
			$v = $next;
		}

		return $face;
	}

	/**
	 * @param list<array{Point, Point}> $segments
	 * @param array{Point, Point} $segment the one at `$index`
	 */
	private function addSegment(array $segments, int $index, array $segment): void
	{
		[$a, $b] = $segment;
		$length = hypot($b[0] - $a[0], $b[1] - $a[1]);
		if ($length === 0.0) {
			return;
		}
		$cuts = [0.0, 1.0];
		foreach ($segments as $j => [$c, $d]) {
			if ($index !== $j) {
				array_push($cuts, ...self::cuts($a, $b, $length, $c, $d));
			}
		}
		sort($cuts);

		$previous = $this->vertexAt($a);
		foreach (array_slice($cuts, 1) as $t) {
			$next = $this->vertexAt([$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t]);
			if ($next !== $previous) {
				$this->edges[sprintf('%d:%d', min($previous, $next), max($previous, $next))] = true;
			}
			$previous = $next;
		}
	}

	/** Removes the vertices with a single edge, over again until there are none. */
	private function prune(): void
	{
		$count = count($this->neighbours);
		do {
			$pruned = false;
			for ($u = 0; $u < $count; $u++) {
				if (count($this->neighbours[$u]) !== 1) {
					continue;
				}
				$v = $this->neighbours[$u][0];
				$this->neighbours[$u] = [];
				$this->neighbours[$v] = array_values(array_filter($this->neighbours[$v], static fn (int $w): bool => $w !== $u));
				$pruned = true;
			}
		} while ($pruned);
	}

	/** @param Point $point */
	private function vertexAt(array $point): int
	{
		foreach ($this->vertices as $index => $vertex) {
			if (hypot($vertex[0] - $point[0], $vertex[1] - $point[1]) <= self::Tolerance) {
				return $index;
			}
		}
		$this->vertices[] = $point;

		return count($this->vertices) - 1;
	}

	/**
	 * Where along ab (0..1, ends excluded) the segment cd crosses it or one of its end points lies on it.
	 *
	 * @param Point $a
	 * @param Point $b
	 * @param Point $c
	 * @param Point $d
	 * @return list<float>
	 */
	private static function cuts(array $a, array $b, float $length, array $c, array $d): array
	{
		$cuts = [];
		$r = [$b[0] - $a[0], $b[1] - $a[1]];
		$s = [$d[0] - $c[0], $d[1] - $c[1]];
		$crossing = self::crossing($a, $r, $length, $c, $s);
		if ($crossing !== null) {
			$cuts[] = $crossing;
		}
		foreach ([$c, $d] as $end) {
			$t = (($end[0] - $a[0]) * $r[0] + ($end[1] - $a[1]) * $r[1]) / ($length * $length);
			$distance = abs(PlanMath::cross($r, [$end[0] - $a[0], $end[1] - $a[1]])) / $length;
			if ($distance <= self::Tolerance && self::inside($t, $length)) {
				$cuts[] = $t;
			}
		}

		return $cuts;
	}

	/**
	 * Where along a + t·r the segment c + u·s crosses it, if it does away from a's ends.
	 *
	 * @param Point $a
	 * @param Point $r
	 * @param Point $c
	 * @param Point $s
	 */
	private static function crossing(array $a, array $r, float $length, array $c, array $s): ?float
	{
		$denominator = PlanMath::cross($r, $s);
		if (abs($denominator) <= 1e-9) {
			return null;
		}
		$ac = [$c[0] - $a[0], $c[1] - $a[1]];
		$t = PlanMath::cross($ac, $s) / $denominator;
		$u = PlanMath::cross($ac, $r) / $denominator;
		$sLength = hypot($s[0], $s[1]);

		return self::inside($t, $length) && $u * $sLength >= -self::Tolerance && (1 - $u) * $sLength >= -self::Tolerance ? $t : null;
	}

	/** Whether the fraction t of a segment `length` mm long is more than `Tolerance` away from both its ends. */
	private static function inside(float $t, float $length): bool
	{
		return $t * $length > self::Tolerance && (1 - $t) * $length > self::Tolerance;
	}
}
