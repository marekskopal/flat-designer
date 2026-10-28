<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * A room under the roofs (FD-38): its clear height's range (mm, from its floor to the ceiling, which is its height or the
 * roof's underside where that is lower, not less than 0), its volume (mm³, under that ceiling) and the area (mm²) where
 * the clear height is at least `Headroom`, and the ceiling's area (mm², its sloped parts along the slope). Measured on the roofs' underside itself (the viewer keeps its sloped ceiling
 * 10 mm under it). Twin of `roomUnderRoofs()` (`viewer/engine/under-roof.ts`), kept identical by the room cases of
 * `tests/fixtures/roof-geometry.json`.
 *
 * @phpstan-import-type Point from PlanMath
 */
final readonly class RoomUnderRoof
{
	/** mm: the clear height from which floor area counts as usable under a roof (attics usually count it so). */
	public const int Headroom = 1200;

	/** mm²: an area this small is none. */
	private const float NoArea = 1.0;

	public function __construct(
		public float $minHeight,
		public float $maxHeight,
		public float $volume,
		public float $headroomArea,
		public float $ceilingArea,
	) {
	}

	/**
	 * Null for an open-air room (no ceiling height) or a vaulted one, which the roofs do not cut, and for one no roof
	 * reaches.
	 *
	 * @param list<Point> $polygon
	 */
	public static function measure(array $polygon, ?int $ceilingHeight, int $floorElevation, bool $vaulted, RoofCover $cover): ?self
	{
		if ($ceilingHeight === null || $vaulted || $cover->pieces === []) {
			return null;
		}
		$total = PlanMath::signedArea($polygon);
		$room = $total < 0 ? array_reverse($polygon) : $polygon;
		$sum = new RoomUnderRoofSum($ceilingHeight, $floorElevation);
		foreach ($cover->pieces as $piece) {
			$part = ConvexClip::within($room, ConvexClip::inside($piece['polygon']));
			if ($part !== []) {
				$sum->add($part, $piece);
			}
		}
		if ($sum->covered < self::NoArea) {
			return null;
		}
		$sum->addOpen(abs($total) - $sum->covered, self::NoArea);

		return new self($sum->lowest, $sum->highest, $sum->volume, $sum->headroom, $sum->ceiling);
	}
}
