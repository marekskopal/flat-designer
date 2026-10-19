<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * Turns a face of `FaceGraph` into a room polygon: (nearly) collinear vertices dropped, rounded to whole
 * mm, starting at the lowest, then leftmost vertex.
 *
 * @phpstan-import-type Point from PlanMath
 */
final readonly class OutlineCleaner
{
	/** A vertex closer than this (mm) to the line through its neighbours is dropped. */
	private const float Collinear = 0.5;

	/**
	 * @param list<Point> $face
	 * @return list<array{int, int}>
	 */
	public static function clean(array $face): array
	{
		return self::startAtLowest(self::round(self::dropCollinear($face)));
	}

	/**
	 * @param list<Point> $points
	 * @return list<Point>
	 */
	private static function dropCollinear(array $points): array
	{
		$count = count($points);
		for ($i = 0; $count > 3 && $i < $count; $i++) {
			if (PlanMath::distanceToLine($points[$i], $points[($i - 1 + $count) % $count], $points[($i + 1) % $count]) < self::Collinear) {
				array_splice($points, $i, 1);

				return self::dropCollinear($points);
			}
		}

		return $points;
	}

	/**
	 * @param list<Point> $points
	 * @return list<array{int, int}> without repeated neighbours
	 */
	private static function round(array $points): array
	{
		$rounded = [];
		foreach ($points as [$x, $y]) {
			$vertex = [PlanMath::roundHalfUp($x), PlanMath::roundHalfUp($y)];
			if ($rounded === [] || $rounded[count($rounded) - 1] !== $vertex) {
				$rounded[] = $vertex;
			}
		}
		if (count($rounded) > 1 && $rounded[0] === $rounded[count($rounded) - 1]) {
			array_pop($rounded);
		}

		return $rounded;
	}

	/**
	 * @param list<array{int, int}> $points
	 * @return list<array{int, int}>
	 */
	private static function startAtLowest(array $points): array
	{
		$first = 0;
		foreach ($points as $i => [$x, $y]) {
			[$firstX, $firstY] = $points[$first];
			if ($y < $firstY || $y === $firstY && $x < $firstX) {
				$first = $i;
			}
		}

		return [...array_slice($points, $first), ...array_slice($points, 0, $first)];
	}
}
