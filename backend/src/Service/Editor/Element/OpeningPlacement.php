<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\Geometry\WallLine;

/**
 * Where an opening may sit along its wall. Besides the opening itself, a sliding or pocket door takes the
 * stretch of wall its leaf slides into when open (towards `hinge`; a lift-and-slide door with fixed panes slides
 * within its opening); a pocket door also needs a wall thick enough for the pocket. A niche keeps a back of the wall
 * behind it. The frame's inset and a garage door's headroom are `OpeningClearances`.
 */
final class OpeningPlacement
{
	/** How far a sliding door's leaf overlaps the opening on each edge, mm (the TS `SlidingDoorOverlap`). */
	public const int SlidingDoorOverlap = 50;

	public const int MinPocketWallThickness = 100;

	/** Wall a niche leaves behind it, mm. */
	public const int MinNicheBack = 30;

	public const int MinNicheDepth = 10;

	/**
	 * A niche's depth in a wall `wallThickness` thick: as given (none: half the wall), leaving `MinNicheBack` behind
	 * it. Null for other types.
	 */
	public static function nicheDepth(OpeningTypeEnum $type, ?int $depth, int $wallThickness): ?int
	{
		if ($type !== OpeningTypeEnum::Niche) {
			return null;
		}
		$max = $wallThickness - self::MinNicheBack;
		if ($max < self::MinNicheDepth) {
			throw EditException::invalid(sprintf('A niche needs a wall at least %d mm thick.', self::MinNicheBack + self::MinNicheDepth));
		}
		$depth ??= intdiv($wallThickness, 2);
		if ($depth < self::MinNicheDepth || $depth > $max) {
			throw EditException::invalid(sprintf(
				'A niche in this wall (%d mm thick) is %d to %d mm deep, leaving %d mm of wall behind it.',
				$wallThickness,
				self::MinNicheDepth,
				$max,
				self::MinNicheBack,
			));
		}

		return $depth;
	}

	/**
	 * The part of the wall (mm along it) an opening takes.
	 *
	 * @return array{int, int}
	 */
	public static function occupied(OpeningTypeEnum $type, ?OpeningHingeEnum $hinge, int $offset, int $width, int $fixedPanes = 0): array
	{
		if (!$type->isSliding() || $fixedPanes > 0) {
			return [$offset, $offset + $width];
		}
		// The open leaf clears the opening: a pocket door's leaf is as wide as it, a sliding one overlaps both edges.
		$travel = $width + ($type === OpeningTypeEnum::SlidingDoor ? 2 * self::SlidingDoorOverlap : 0);

		return $hinge === OpeningHingeEnum::End ? [$offset, $offset + $width + $travel] : [$offset - $travel, $offset + $width];
	}

	/**
	 * Checks an opening (already within the wall) against the wall's length and thickness and its other openings. Two
	 * niches may share a stretch of wall from opposite faces (`side`, `depth`) as long as the wall between them stays.
	 *
	 * @param iterable<Opening> $others the wall's other openings
	 */
	public static function check(
		OpeningTypeEnum $type,
		?OpeningHingeEnum $hinge,
		int $offset,
		int $width,
		int $wallLength,
		int $wallThickness,
		iterable $others,
		?OpeningSideEnum $side = null,
		?int $depth = null,
		int $fixedPanes = 0,
	): void {
		if ($type === OpeningTypeEnum::PocketDoor && $wallThickness < self::MinPocketWallThickness) {
			throw EditException::invalid(sprintf('A pocket door needs a wall at least %d mm thick.', self::MinPocketWallThickness));
		}
		[$from, $to] = self::occupied($type, $hinge, $offset, $width, $fixedPanes);
		if ($from < 0 || $to > $wallLength) {
			throw EditException::invalid(sprintf(
				'The open %s needs %d mm of wall beside the opening towards its hinge edge; there is %d mm.',
				$type->label(),
				$to - $from - $width,
				$hinge === OpeningHingeEnum::End ? $wallLength - $offset - $width : $offset,
			));
		}

		foreach ($others as $other) {
			if ($type === OpeningTypeEnum::Niche && self::backToBack($side, $depth, $other, $wallThickness)) {
				continue;
			}
			self::checkApart($offset, $width, $from, $to, $other);
		}
	}

	/** The opening (`offset`, `width`; occupying `from`..`to`) must not overlap `other` or the wall its open leaf needs. */
	private static function checkApart(int $offset, int $width, int $from, int $to, Opening $other): void
	{
		if ($offset < $other->offset + $other->width && $other->offset < $offset + $width) {
			throw EditException::invalid(sprintf('The opening overlaps "%s" on this wall.', $other->name));
		}
		[$otherFrom, $otherTo] = self::occupied($other->type, $other->hinge, $other->offset, $other->width, $other->fixedPanes);
		if ($from < $otherTo && $otherFrom < $to) {
			throw EditException::invalid(sprintf('The opening or "%s" needs that part of the wall for its open leaf.', $other->name));
		}
	}

	/** Whether `other` is a niche in the opposite face that a niche `depth` deep in `side` leaves `MinNicheBack` of wall to. */
	private static function backToBack(?OpeningSideEnum $side, ?int $depth, Opening $other, int $wallThickness): bool
	{
		return $other->type === OpeningTypeEnum::Niche
			&& $other->side !== null
			&& $other->side !== $side
			&& ($depth ?? 0) + ($other->depth ?? 0) <= $wallThickness - self::MinNicheBack;
	}

	/**
	 * Whether the niches of a wall `wallThickness` thick leave `MinNicheBack` of wall where two of them meet from
	 * opposite faces.
	 *
	 * @param list<Opening> $openings
	 */
	public static function nichesFit(array $openings, int $wallThickness): bool
	{
		$niches = array_values(array_filter($openings, static fn (Opening $o): bool => $o->type === OpeningTypeEnum::Niche));
		foreach ($niches as $i => $niche) {
			foreach (array_slice($niches, $i + 1) as $other) {
				$overlap = $niche->offset < $other->offset + $other->width && $other->offset < $niche->offset + $niche->width;
				if (
					$overlap
					&& $niche->side !== $other->side
					&& ($niche->depth ?? 0) + ($other->depth ?? 0) > $wallThickness - self::MinNicheBack
				) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Whether an opening `width` wide (a sliding or pocket door with the wall its open leaf takes) is shallow enough for
	 * a wall curved at `radius` (null: straight): built straight, it may stand off the arc by a quarter of the wall's
	 * thickness at most. Niches follow the curve. Twin of `openingFitsCurve()` in `plan-geometry.ts`.
	 */
	public static function fitsCurve(
		OpeningTypeEnum $type,
		?OpeningHingeEnum $hinge,
		int $width,
		?float $radius,
		int $wallThickness,
		int $fixedPanes = 0,
	): bool {
		if ($radius === null || $type === OpeningTypeEnum::Niche) {
			return true;
		}
		[$from, $to] = self::occupied($type, $hinge, 0, $width, $fixedPanes);

		return $radius * (1 - cos(($to - $from) / (2 * $radius))) <= $wallThickness / 4;
	}

	/** On a curved wall a door or window is built straight, so it has to be shallow (`fitsCurve()`). */
	public static function checkCurve(Wall $wall, OpeningTypeEnum $type, ?OpeningHingeEnum $hinge, int $width, int $fixedPanes): void
	{
		$radius = WallLine::fromWall($wall)->arc()['radius'] ?? null;
		if ($radius !== null && !self::fitsCurve($type, $hinge, $width, $radius, $wall->thickness, $fixedPanes)) {
			throw EditException::invalid(sprintf(
				'On this curved wall a door or window is built straight, so it may take %d mm of the wall at most%s.',
				self::maxCurvedWidth($radius, $wall->thickness),
				$type->isSliding() ? ' with the wall its open leaf slides along' : '',
			));
		}
	}

	/** The widest opening (mm) `fitsCurve()` allows on a wall curved at `radius`. */
	public static function maxCurvedWidth(float $radius, int $wallThickness): int
	{
		return (int) floor(2 * $radius * acos(max(1 - $wallThickness / 4 / $radius, -1.0)));
	}

	/** Whether an existing opening, at `offset`, still fits a changed wall (curved at `wallRadius`; null: straight). */
	public static function fits(
		Opening $opening,
		int $offset,
		int $wallLength,
		int $wallThickness,
		int $wallHeight,
		?float $wallRadius = null,
	): bool
	{
		[$from, $to] = self::occupied($opening->type, $opening->hinge, $offset, $opening->width, $opening->fixedPanes);

		return $from >= 0
			&& $to <= $wallLength
			&& OpeningClearances::fit($opening, $wallThickness, $wallHeight)
			&& ($opening->type !== OpeningTypeEnum::PocketDoor || $wallThickness >= self::MinPocketWallThickness)
			&& ($opening->type !== OpeningTypeEnum::Niche || ($opening->depth ?? 0) <= $wallThickness - self::MinNicheBack)
			&& self::fitsCurve($opening->type, $opening->hinge, $opening->width, $wallRadius, $wallThickness, $opening->fixedPanes);
	}
}
