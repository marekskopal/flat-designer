<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Service\Editor\Exception\EditException;

/** Contour lines read off a site plan, turned into a plot's spot heights (FD-86, MCP `set_terrain`). */
final readonly class ContourLines
{
	/** The longest gap (mm) between the spot heights a contour line is sampled at. */
	private const int Step = 2000;

	/**
	 * Contour lines `[{z, points: [[x, y], …]}, …]`, whole mm, at least two points each.
	 *
	 * @return list<array{z: int, points: list<array{int, int}>}>
	 */
	public static function validate(mixed $contours): array
	{
		if (!is_array($contours) || !array_is_list($contours)) {
			throw EditException::invalid('Contours must be a list of {z, points}.');
		}
		$result = [];
		foreach ($contours as $contour) {
			$z = is_array($contour) ? $contour['z'] ?? null : null;
			$line = is_array($contour) ? $contour['points'] ?? null : null;
			if (!is_int($z) || !is_array($line) || !array_is_list($line) || count($line) < 2) {
				throw EditException::invalid('Every contour needs a whole-mm z and at least two [x, y] points.');
			}
			$result[] = ['z' => $z, 'points' => array_map(self::point(...), $line)];
		}

		return $result;
	}

	/**
	 * Spot heights from contour lines: each line's points at its level, and points along its segments at most `Step`
	 * apart. Points closer than `SpotHeights::MinSpacing` to one taken before are left out, so the given spot heights
	 * (first in `$points`) win.
	 *
	 * @param list<array{int, int, int}> $points
	 * @param list<array{z: int, points: list<array{int, int}>}> $contours
	 * @return list<array{int, int, int}>
	 */
	public static function withContours(array $points, array $contours): array
	{
		$result = $points;
		foreach ($contours as ['z' => $z, 'points' => $line]) {
			foreach ($line as $i => [$x, $y]) {
				if ($i > 0) {
					[$px, $py] = $line[$i - 1];
					$steps = (int) ceil(hypot($x - $px, $y - $py) / self::Step);
					for ($s = 1; $s < $steps; $s++) {
						$result = self::add(
							$result,
							[(int) round($px + ($x - $px) * $s / $steps), (int) round($py + ($y - $py) * $s / $steps), $z],
						);
					}
				}
				$result = self::add($result, [$x, $y, $z]);
			}
		}

		return $result;
	}

	/** @return array{int, int} */
	private static function point(mixed $point): array
	{
		if (!is_array($point) || count($point) !== 2 || !is_int($point[0] ?? null) || !is_int($point[1] ?? null)) {
			throw EditException::invalid('Every contour point must be [x, y] in whole mm.');
		}

		return [$point[0], $point[1]];
	}

	/**
	 * @param list<array{int, int, int}> $points
	 * @param array{int, int, int} $point
	 * @return list<array{int, int, int}>
	 */
	private static function add(array $points, array $point): array
	{
		foreach ($points as [$x, $y]) {
			if (abs($x - $point[0]) < SpotHeights::MinSpacing && abs($y - $point[1]) < SpotHeights::MinSpacing) {
				return $points;
			}
		}
		$points[] = $point;

		return $points;
	}
}
