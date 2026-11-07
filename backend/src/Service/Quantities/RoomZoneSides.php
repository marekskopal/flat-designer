<?php

declare(strict_types=1);

namespace Izbik\Service\Quantities;

/** The sides of a dropped ceiling that show: its edges but those lying along the room's outline (against a wall). */
final readonly class RoomZoneSides
{
	/** mm: how far off the outline an edge's ends may lie and still be against it. */
	private const float Tolerance = 1.0;

	/**
	 * The length of a zone's edges not lying along the room's outline, mm.
	 *
	 * @param list<array{float, float}> $zone
	 * @param list<array{float, float}> $outline
	 */
	public static function exposed(array $zone, array $outline): float
	{
		$length = 0.0;
		$count = count($zone);
		foreach ($zone as $i => $a) {
			$b = $zone[($i + 1) % $count];
			if (!self::alongOutline($a, $b, $outline)) {
				$length += hypot($b[0] - $a[0], $b[1] - $a[1]);
			}
		}

		return $length;
	}

	/**
	 * Whether the edge a → b lies on one edge of the outline.
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @param list<array{float, float}> $outline
	 */
	private static function alongOutline(array $a, array $b, array $outline): bool
	{
		$count = count($outline);
		foreach ($outline as $i => $p) {
			$q = $outline[($i + 1) % $count];
			if (self::distance($a, $p, $q) <= self::Tolerance && self::distance($b, $p, $q) <= self::Tolerance) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{float, float} $point
	 * @param array{float, float} $p
	 * @param array{float, float} $q
	 */
	private static function distance(array $point, array $p, array $q): float
	{
		$dx = $q[0] - $p[0];
		$dy = $q[1] - $p[1];
		$lengthSquared = $dx * $dx + $dy * $dy;
		$t = $lengthSquared === 0.0 ? 0.0 : max(0.0, min(1.0, (($point[0] - $p[0]) * $dx + ($point[1] - $p[1]) * $dy) / $lengthSquared));

		return hypot($point[0] - $p[0] - $t * $dx, $point[1] - $p[1] - $t * $dy);
	}
}
