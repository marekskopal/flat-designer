<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\RoofWindow;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\Geometry\PlanMath;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use const M_PI;

/**
 * Where a roof window may go (FD-38): on a pitched plane, its frame `Margin` inside the plane's outline in plan (clear of
 * the eaves, verges, ridge and hips) and not overlapping another window of that plane. The window lies `width` across
 * the slope and `length` up it, so in plan it is `length` · cos(pitch) deep.
 *
 * @phpstan-import-type Point from PlanMath
 */
final readonly class RoofWindowPlacement
{
	/** mm in plan between a window's frame and its plane's edges. */
	public const int Margin = 300;

	/** @param list<RoofWindow> $others the roof's other windows */
	public static function check(RoofGeometry $roof, int $along, int $across, int $width, int $length, array $others): void
	{
		if (!$roof->shape->isPitched()) {
			throw EditException::invalid('A roof window needs a pitched roof.');
		}
		$index = $roof->localPlaneAt($along, $across) ?? throw EditException::invalid(sprintf(
			'The roof window\'s centre (along %d, across %d) lies off the roof.',
			$along,
			$across,
		));
		$plane = $roof->localPlanes()[$index];
		[$up, $side] = self::axes($plane['gs'], $plane['gt']);
		$halfWidth = $width / 2;
		$halfDepth = $length * cos($roof->pitch * M_PI / 180) / 2;
		foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$i, $j]) {
			$corner = [
				$along + $side[0] * $halfWidth * $i + $up[0] * $halfDepth * $j,
				$across + $side[1] * $halfWidth * $i + $up[1] * $halfDepth * $j,
			];
			if (!self::inside($plane['polygon'], $corner)) {
				throw EditException::invalid(sprintf(
					'A roof window must stay %d mm inside its roof plane, clear of the eaves, verges, ridge and hips.',
					self::Margin,
				));
			}
		}

		foreach ($others as $other) {
			if ($roof->localPlaneAt($other->along, $other->across) !== $index) {
				continue;
			}
			$ds = $other->along - $along;
			$dt = $other->across - $across;
			$otherDepth = $other->length * cos($roof->pitch * M_PI / 180) / 2;
			if (
				abs($ds * $side[0] + $dt * $side[1]) < $halfWidth + $other->width / 2
				&& abs($ds * $up[0] + $dt * $up[1]) < $halfDepth + $otherDepth
			) {
				throw EditException::invalid(sprintf('The roof window overlaps roof window "%s".', $other->name));
			}
		}
	}

	/**
	 * Up the slope and across it (to the left of up), unit vectors in the roof's frame.
	 *
	 * @return array{Point, Point}
	 */
	private static function axes(float $gs, float $gt): array
	{
		$length = sqrt($gs * $gs + $gt * $gt);
		$up = [$gs / $length, $gt / $length];

		return [$up, [-$up[1], $up[0]]];
	}

	/**
	 * At least `Margin` inside a counter-clockwise convex outline (every roof plane is one).
	 *
	 * @param list<Point> $polygon
	 * @param Point $point
	 */
	private static function inside(array $polygon, array $point): bool
	{
		$count = count($polygon);
		for ($i = 0; $i < $count; $i++) {
			[$ax, $ay] = $polygon[$i];
			[$bx, $by] = $polygon[($i + 1) % $count];
			$edge = sqrt(($bx - $ax) ** 2 + ($by - $ay) ** 2);
			if ((($bx - $ax) * ($point[1] - $ay) - ($by - $ay) * ($point[0] - $ax)) / $edge < self::Margin - 1e-6) {
				return false;
			}
		}

		return true;
	}
}
