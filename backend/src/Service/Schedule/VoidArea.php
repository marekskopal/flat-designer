<?php

declare(strict_types=1);

namespace Izbik\Service\Schedule;

use Izbik\Dto\FloorVoidDto;
use Izbik\Service\Editor\Geometry\PolygonOverlap;

/**
 * How much of an outline the floor voids take: a room's floor lost to a void through it, or a ceiling under the
 * voids of the floor above. Voids are taken not to overlap one another. The stairs' voids are the viewer's (their
 * footprints are TS only) and are not counted.
 */
final readonly class VoidArea
{
	/**
	 * mm² of the outline under the voids.
	 *
	 * @param list<array{int, int}> $outline
	 * @param list<FloorVoidDto> $voids
	 */
	public static function of(array $outline, array $voids): float
	{
		$subject = self::points($outline);
		$area = 0.0;
		foreach ($voids as $void) {
			$area += PolygonOverlap::area($subject, self::points($void->polygon));
		}

		return $area;
	}

	/**
	 * @param list<array{int, int}> $polygon
	 * @return list<array{float, float}>
	 */
	private static function points(array $polygon): array
	{
		return array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $polygon);
	}
}
