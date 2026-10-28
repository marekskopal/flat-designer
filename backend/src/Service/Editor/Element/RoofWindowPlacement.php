<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\RoofWindow;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Editor\Geometry\RoofWindowGeometry;
use const M_PI;

/**
 * Where a roof window may go (FD-38): on a pitched plane, its frame `Margin` inside the plane's outline in plan (clear of
 * the eaves, verges, ridge and hips; `RoofWindowGeometry::fits()`, twinned in the viewer) and not overlapping another
 * window of that plane. The window lies `width` across the slope and `length` up it, so in plan it is
 * `length` · cos(pitch) deep.
 */
final readonly class RoofWindowPlacement
{
	/** mm in plan between a window's frame and its plane's edges. */
	public const int Margin = RoofWindowGeometry::Margin;

	/** @param list<RoofWindow> $others the roof's other windows */
	public static function check(RoofGeometry $roof, int $along, int $across, int $width, int $length, array $others): void
	{
		if (!$roof->shape->isPitched()) {
			throw EditException::invalid('A roof window needs a pitched roof.');
		}
		$placed = RoofWindowGeometry::corners($roof, $along, $across, $width, $length) ?? throw EditException::invalid(sprintf(
			'The roof window\'s centre (along %d, across %d) lies off the roof.',
			$along,
			$across,
		));
		if (!RoofWindowGeometry::fits($roof, $along, $across, $width, $length)) {
			throw EditException::invalid(sprintf(
				'A roof window must stay %d mm inside its roof plane, clear of the eaves, verges, ridge and hips.',
				self::Margin,
			));
		}
		['plane' => $index, 'up' => $up, 'side' => $side] = $placed;
		$halfWidth = $width / 2;
		$halfDepth = $length * cos($roof->pitch * M_PI / 180) / 2;

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
}
