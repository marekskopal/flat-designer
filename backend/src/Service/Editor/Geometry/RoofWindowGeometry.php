<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use const M_PI;

/**
 * Where a roof window lies on its roof (FD-38) and whether it fits its plane. Twin of `roofWindowCorners()` /
 * `roofWindowFits()` in `viewer/engine/roof-geometry.ts`, kept identical by `tests/fixtures/roof-geometry.json`.
 *
 * @phpstan-import-type Point from PlanMath
 * @phpstan-type WindowCorners array{plane: int, up: Point, side: Point, corners: list<Point>}
 */
final readonly class RoofWindowGeometry
{
	/** mm, how far off an edge a point still counts as on it. */
	private const float Epsilon = 1e-6;

	/** mm in plan between a roof window's frame and its plane's edges (`RoofWindowMargin` in TS). */
	public const int Margin = 300;

	/**
	 * A window in the roof's frame: on the plane under its centre, `width` across the slope and `length` up it, so
	 * `length` · cos(pitch) deep in plan. `up` is up the slope and `side` across it to the left of up, both unit vectors;
	 * `corners` go counter-clockwise from the low left (a corner may lie off the plane). Null on a flat roof, or with
	 * the centre off the roof.
	 *
	 * @return ?WindowCorners
	 */
	public static function corners(RoofGeometry $roof, float $along, float $across, float $width, float $length): ?array
	{
		if (!$roof->shape->isPitched()) {
			return null;
		}
		$index = $roof->localPlaneAt($along, $across);
		if ($index === null) {
			return null;
		}
		$plane = $roof->localPlanes()[$index];
		$steep = sqrt($plane['gs'] ** 2 + $plane['gt'] ** 2);
		$up = [$plane['gs'] / $steep, $plane['gt'] / $steep];
		$side = [-$up[1], $up[0]];
		$halfWidth = $width / 2;
		$halfDepth = $length * cos($roof->pitch * M_PI / 180) / 2;
		$corners = [];
		// Low left, low right, high right, high left (`side` is left of `up`): counter-clockwise.
		foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$i, $j]) {
			$corners[] = [
				$along - $side[0] * $halfWidth * $i + $up[0] * $halfDepth * $j,
				$across - $side[1] * $halfWidth * $i + $up[1] * $halfDepth * $j,
			];
		}

		return ['plane' => $index, 'up' => $up, 'side' => $side, 'corners' => $corners];
	}

	/**
	 * Whether a window may lie there: on a pitched plane, every corner `Margin` inside its outline in plan (clear of the
	 * eaves, verges, ridge and hips). The plane's other windows are not looked at.
	 */
	public static function fits(RoofGeometry $roof, float $along, float $across, float $width, float $length): bool
	{
		$placed = self::corners($roof, $along, $across, $width, $length);
		if ($placed === null) {
			return false;
		}
		$polygon = $roof->localPlanes()[$placed['plane']]['polygon'];
		foreach ($placed['corners'] as $corner) {
			if (!self::insideBy($polygon, $corner, self::Margin)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * At least `margin` inside a counter-clockwise convex outline (every roof plane is one).
	 *
	 * @param list<Point> $polygon
	 * @param Point $point
	 */
	private static function insideBy(array $polygon, array $point, float $margin): bool
	{
		$count = count($polygon);
		for ($i = 0; $i < $count; $i++) {
			[$ax, $ay] = $polygon[$i];
			[$bx, $by] = $polygon[($i + 1) % $count];
			$edge = sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2);
			if ((($bx - $ax) * ($point[1] - $ay) - ($by - $ay) * ($point[0] - $ax)) / $edge < $margin - self::Epsilon) {
				return false;
			}
		}

		return true;
	}
}
