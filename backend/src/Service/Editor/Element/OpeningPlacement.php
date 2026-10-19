<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * Where an opening may sit along its wall. Besides the opening itself, a sliding or pocket door takes the
 * stretch of wall its leaf slides into when open (towards `hinge`); a pocket door also needs a wall thick
 * enough for the pocket.
 */
final class OpeningPlacement
{
	/** How far a sliding door's leaf overlaps the opening on each edge, mm (the TS `SlidingDoorOverlap`). */
	public const int SlidingDoorOverlap = 50;

	public const int MinPocketWallThickness = 100;

	/**
	 * The part of the wall (mm along it) an opening takes.
	 *
	 * @return array{int, int}
	 */
	public static function occupied(OpeningTypeEnum $type, ?OpeningHingeEnum $hinge, int $offset, int $width): array
	{
		if (!$type->isSliding()) {
			return [$offset, $offset + $width];
		}
		// The open leaf clears the opening: a pocket door's leaf is as wide as it, a sliding one overlaps both edges.
		$travel = $width + ($type === OpeningTypeEnum::SlidingDoor ? 2 * self::SlidingDoorOverlap : 0);

		return $hinge === OpeningHingeEnum::End ? [$offset, $offset + $width + $travel] : [$offset - $travel, $offset + $width];
	}

	/**
	 * Checks an opening (already within the wall) against the wall's length and thickness and its other openings.
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
	): void {
		if ($type === OpeningTypeEnum::PocketDoor && $wallThickness < self::MinPocketWallThickness) {
			throw EditException::invalid(sprintf('A pocket door needs a wall at least %d mm thick.', self::MinPocketWallThickness));
		}
		[$from, $to] = self::occupied($type, $hinge, $offset, $width);
		if ($from < 0 || $to > $wallLength) {
			throw EditException::invalid(sprintf(
				'The open %s needs %d mm of wall beside the opening towards its hinge edge; there is %d mm.',
				$type->label(),
				$to - $from - $width,
				$hinge === OpeningHingeEnum::End ? $wallLength - $offset - $width : $offset,
			));
		}

		foreach ($others as $other) {
			if ($offset < $other->offset + $other->width && $other->offset < $offset + $width) {
				throw EditException::invalid(sprintf('The opening overlaps "%s" on this wall.', $other->name));
			}
			[$otherFrom, $otherTo] = self::occupied($other->type, $other->hinge, $other->offset, $other->width);
			if ($from < $otherTo && $otherFrom < $to) {
				throw EditException::invalid(sprintf('The opening or "%s" needs that part of the wall for its open leaf.', $other->name));
			}
		}
	}

	/** Whether an existing opening, at `offset`, still fits a changed wall. */
	public static function fits(Opening $opening, int $offset, int $wallLength, int $wallThickness, int $wallHeight): bool
	{
		[$from, $to] = self::occupied($opening->type, $opening->hinge, $offset, $opening->width);

		return $from >= 0
			&& $to <= $wallLength
			&& $opening->sillHeight + $opening->height <= $wallHeight
			&& ($opening->type !== OpeningTypeEnum::PocketDoor || $wallThickness >= self::MinPocketWallThickness);
	}
}
