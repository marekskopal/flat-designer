<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * A plot's terrain (FD-86): up to `MaxPoints` spot heights [x, y, z] in whole mm, at least `MinSpacing` apart in plan.
 * `Terrain` triangulates them.
 */
final readonly class SpotHeights
{
	public const int MaxPoints = 1000;

	/** Two spot heights closer than this (mm) would make a cliff, or a duplicate. */
	public const int MinSpacing = 10;

	/** @return list<array{int, int, int}> */
	public static function validate(mixed $terrain, int $coordinateLimit, int $heightLimit): array
	{
		if (!is_array($terrain) || !array_is_list($terrain) || count($terrain) > self::MaxPoints) {
			throw EditException::invalid(sprintf('Field "terrain" must be a list of at most %d [x, y, z] spot heights.', self::MaxPoints));
		}
		$points = [];
		foreach ($terrain as $point) {
			if (
				!is_array($point)
				|| count($point) !== 3
				|| !is_int($point[0] ?? null)
				|| !is_int($point[1] ?? null)
				|| !is_int($point[2] ?? null)
				|| abs($point[0]) > $coordinateLimit
				|| abs($point[1]) > $coordinateLimit
				|| abs($point[2]) > $heightLimit
			) {
				throw EditException::invalid(sprintf(
					'Every spot height must be [x, y, z] in whole mm, x and y within ±%d, z within ±%d.',
					$coordinateLimit,
					$heightLimit,
				));
			}
			foreach ($points as [$x, $y]) {
				if (abs($x - $point[0]) < self::MinSpacing && abs($y - $point[1]) < self::MinSpacing) {
					throw EditException::invalid(sprintf(
						'The spot heights at [%d, %d] and [%d, %d] are less than %d mm apart.',
						$x,
						$y,
						$point[0],
						$point[1],
						self::MinSpacing,
					));
				}
			}
			$points[] = [$point[0], $point[1], $point[2]];
		}

		return $points;
	}
}
