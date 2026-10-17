<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Service\Editor\Exception\EditException;

/** Room outlines: a simple polygon (no self-intersection) of 3..200 integer points with an area. */
final readonly class PolygonValidator
{
	private const int MaxPoints = 200;

	/** @return list<array{int, int}> */
	public static function validate(mixed $polygon, int $coordinateLimit): array
	{
		if (!is_array($polygon) || !array_is_list($polygon) || count($polygon) < 3 || count($polygon) > self::MaxPoints) {
			throw EditException::invalid(sprintf('Field "polygon" must be a list of 3 to %d [x, y] points.', self::MaxPoints));
		}

		$points = self::points($polygon, $coordinateLimit);

		$count = count($points);
		for ($i = 0; $i < $count; $i++) {
			if ($points[$i] === $points[($i + 1) % $count]) {
				throw EditException::invalid('The polygon repeats a point.');
			}
		}

		if (self::doubleArea($points) === 0) {
			throw EditException::invalid('The polygon has no area.');
		}

		if (self::selfIntersects($points)) {
			throw EditException::invalid('The polygon crosses itself.');
		}

		return $points;
	}

	/**
	 * @param array<mixed> $polygon
	 * @return list<array{int, int}>
	 */
	private static function points(array $polygon, int $coordinateLimit): array
	{
		$points = [];
		foreach ($polygon as $point) {
			if (
				!is_array($point)
				|| count($point) !== 2
				|| !is_int($point[0] ?? null)
				|| !is_int($point[1] ?? null)
				|| abs($point[0]) > $coordinateLimit
				|| abs($point[1]) > $coordinateLimit
			) {
				throw EditException::invalid(sprintf('Every polygon point must be [x, y] in whole mm within ±%d.', $coordinateLimit));
			}
			$points[] = [$point[0], $point[1]];
		}

		return $points;
	}

	/** @param list<array{int, int}> $points */
	private static function selfIntersects(array $points): bool
	{
		$count = count($points);
		for ($i = 0; $i < $count; $i++) {
			for ($j = $i + 2; $j < $count; $j++) {
				// Neighbouring edges share a point by definition (the last edge neighbours the first).
				if ($i === 0 && $j === $count - 1) {
					continue;
				}
				if (self::segmentsIntersect($points[$i], $points[($i + 1) % $count], $points[$j], $points[($j + 1) % $count])) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param list<array{int, int}> $points */
	private static function doubleArea(array $points): int
	{
		$sum = 0;
		$count = count($points);
		foreach ($points as $i => [$x, $y]) {
			[$nextX, $nextY] = $points[($i + 1) % $count];
			$sum += $x * $nextY - $nextX * $y;
		}

		return abs($sum);
	}

	/**
	 * Proper or touching intersection of segments ab and cd.
	 *
	 * @param array{int, int} $a
	 * @param array{int, int} $b
	 * @param array{int, int} $c
	 * @param array{int, int} $d
	 */
	private static function segmentsIntersect(array $a, array $b, array $c, array $d): bool
	{
		$d1 = self::orientation($c, $d, $a);
		$d2 = self::orientation($c, $d, $b);
		$d3 = self::orientation($a, $b, $c);
		$d4 = self::orientation($a, $b, $d);

		if ($d1 * $d2 < 0 && $d3 * $d4 < 0) {
			return true;
		}

		return $d1 === 0 && self::onSegment($c, $d, $a)
			|| $d2 === 0 && self::onSegment($c, $d, $b)
			|| $d3 === 0 && self::onSegment($a, $b, $c)
			|| $d4 === 0 && self::onSegment($a, $b, $d);
	}

	/**
	 * @param array{int, int} $p
	 * @param array{int, int} $q
	 * @param array{int, int} $r
	 */
	private static function orientation(array $p, array $q, array $r): int
	{
		return ($q[0] - $p[0]) * ($r[1] - $p[1]) <=> ($q[1] - $p[1]) * ($r[0] - $p[0]);
	}

	/**
	 * Whether r, known to be collinear with pq, lies on the segment pq.
	 *
	 * @param array{int, int} $p
	 * @param array{int, int} $q
	 * @param array{int, int} $r
	 */
	private static function onSegment(array $p, array $q, array $r): bool
	{
		return $r[0] >= min($p[0], $q[0]) && $r[0] <= max($p[0], $q[0]) && $r[1] >= min($p[1], $q[1]) && $r[1] <= max($p[1], $q[1]);
	}
}
