<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Dto\WallFinishDto;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Service\Editor\Geometry\RoofBreaks;
use FlatDesigner\Service\Editor\Geometry\RoofCover;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Editor\Geometry\Spans;
use FlatDesigner\Service\Editor\Geometry\WallLine;

/**
 * A room's wall face under the roofs (FD-38): its bands and paint as `RoomSurfaces` counts them, up to a top that follows
 * the wall's top under the roofs (its height cut by their underside, or with `upToRoof` the underside) and the room's
 * ceiling. The top is piecewise linear along the face, and so is every height counted, between the openings' edges and
 * `RoofBreaks` at the heights the count turns on; each stretch is summed exactly from its ends.
 *
 * @phpstan-type Face array{wall: WallDto, side: OpeningSideEnum, from: float, to: float, gaps: list<array{from: float, to: float, bottom: int, top: int, niche: bool}>}
 * @phpstan-type Amount array{QuantitySurfaceEnum, int|null, float}
 */
final readonly class RoomFaceUnderRoof
{
	/** mm inside a stretch's ends at which its heights are read (the top may step at a break). */
	private const float Inside = 1e-6;

	/**
	 * @param Face $face on the room edge a → b
	 * @param ?float $wallTop the room's paint top (its ceiling, a vault's mean), null without a ceiling
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @return list<Amount> mm²
	 */
	public static function amounts(RoomDto $room, array $face, ?float $wallTop, RoofCover $cover, array $a, array $b): array
	{
		$bands = $face['wall']->finishesOn($face['side']) ?? $room->wallFinishes;
		$levels = [0.0, (float) $face['wall']->height];
		if ($wallTop !== null) {
			$levels[] = $wallTop;
		}
		foreach ($bands as $band) {
			array_push($levels, (float) $band->bottom, (float) $band->top);
		}
		foreach ($face['gaps'] as $gap) {
			array_push($levels, (float) $gap['bottom'], (float) $gap['top']);
		}
		$floor = (float) $room->floorElevation;
		$length = max(sqrt(($b[0] - $a[0]) ** 2 + ($b[1] - $a[1]) ** 2), 1e-9);
		// The wall's own top along the edge: level, or sloping to `heightEnd` at the wall's end (FD-87).
		$wallHeight = self::wallHeight($face['wall'], $a, $b);
		$top = static function (float $along) use ($cover, $a, $b, $length, $floor, $face, $wallTop, $wallHeight): float {
			$t = $along / $length;
			$underside = RoofGeometry::undersideOf($cover->roofs, $a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t);
			$height = $wallHeight($t);
			$wall = $underside === null ? $height : ($face['wall']->upToRoof ? $underside - $floor : min($height, $underside - $floor));

			return max($wallTop === null ? $wall : min($wall, $wallTop), 0.0);
		};

		$amounts = [];
		$slopes = $face['wall']->heightEnd === null ? [] : [[$wallHeight(0.0) + $floor, $wallHeight(1.0) + $floor]];
		$cuts = self::cuts($face, RoofBreaks::along(
			$cover->roofs,
			$a,
			$b,
			array_map(static fn (float $level): float => $level + $floor, $levels),
			$slopes,
		), $length);
		for ($i = 1, $count = count($cuts); $i < $count; $i++) {
			[$x0, $x1] = [$cuts[$i - 1], $cuts[$i]];
			$middle = ($x0 + $x1) / 2;
			$start = self::heights($face, $bands, $wallTop, $top($x0 + self::Inside), $middle);
			$end = self::heights($face, $bands, $wallTop, $top($x1 - self::Inside), $middle);
			foreach ($start as $k => [$surface, $materialId, $height]) {
				$amounts[] = [$surface, $materialId, ($x1 - $x0) * ($height + ($end[$k][2] ?? $height)) / 2];
			}
		}

		return $amounts;
	}

	/**
	 * The wall's height at 0–1 along the edge a → b: its `height`, sloping straight to `heightEnd` at the wall's end
	 * (the edge runs along a stretch of the wall, wherever along it).
	 *
	 * @param array{float, float} $a
	 * @param array{float, float} $b
	 * @return callable(float): float
	 */
	private static function wallHeight(WallDto $wall, array $a, array $b): callable
	{
		$height = (float) $wall->height;
		if ($wall->heightEnd === null) {
			return static fn (): float => $height;
		}
		$line = WallLine::fromDto($wall);
		$length = max($line->length(), 1e-9);
		$fraction = static fn (array $point): float => max(0.0, min(1.0, $line->along($point) / $length));
		[$atA, $atB] = [$height + ($wall->heightEnd - $height) * $fraction($a), $height + ($wall->heightEnd - $height) * $fraction($b)];

		return static fn (float $t): float => $atA + ($atB - $atA) * $t;
	}

	/**
	 * Where the face is cut into stretches: its ends, the openings' edges and the roofs' breaks, ascending.
	 *
	 * @param Face $face
	 * @param list<float> $breaks 0–1 along the edge
	 * @return list<float>
	 */
	private static function cuts(array $face, array $breaks, float $length): array
	{
		$cuts = [$face['from'], $face['to']];
		foreach ($face['gaps'] as $gap) {
			array_push($cuts, $gap['from'], $gap['to']);
		}
		foreach ($breaks as $t) {
			$cuts[] = $t * $length;
		}
		$cuts = array_values(array_filter($cuts, static fn (float $x): bool => $x >= $face['from'] && $x <= $face['to']));
		sort($cuts);
		$distinct = [];
		foreach ($cuts as $x) {
			if ($distinct === [] || $x - $distinct[count($distinct) - 1] > 1e-9) {
				$distinct[] = $x;
			}
		}

		return $distinct;
	}

	/**
	 * The heights counted at a point of the face under a `top`: each band's and the paint's above them (none without a
	 * ceiling), without the openings there.
	 *
	 * @param Face $face
	 * @param list<WallFinishDto> $bands
	 * @return list<Amount> the third entry a height, mm
	 */
	private static function heights(array $face, array $bands, ?float $wallTop, float $top, float $along): array
	{
		$heights = [];
		$covered = [];
		foreach ($bands as $band) {
			$bottom = max((float) $band->bottom, 0.0);
			$bandTop = min((float) $band->top, $top);
			$heights[] = [QuantitySurfaceEnum::WallFinish, $band->materialId, self::open($face, $along, $bottom, $bandTop)];
			if ($bandTop > $bottom) {
				$covered[] = [$bottom, $bandTop];
			}
		}
		if ($wallTop === null) {
			return $heights;
		}
		foreach (Spans::uncovered($top, $covered) as [$bottom, $paintTop]) {
			$heights[] = [QuantitySurfaceEnum::Wall, $face['wall']->materialId, self::open($face, $along, $bottom, $paintTop)];
		}

		return $heights;
	}

	/**
	 * The height of the face between two heights at a point, without the openings there; 0 when the range is empty.
	 *
	 * @param Face $face
	 */
	private static function open(array $face, float $along, float $bottom, float $top): float
	{
		$height = $top - $bottom;
		foreach ($face['gaps'] as $gap) {
			if ($gap['from'] < $along && $along < $gap['to']) {
				$height -= max(min((float) $gap['top'], $top) - max((float) $gap['bottom'], $bottom), 0.0);
			}
		}

		return max($height, 0.0);
	}
}
