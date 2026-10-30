<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\GarageDoorStyleEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * What an opening needs of its wall across it and above it (FD-89): a frame set in from the outer face (`inset`) stays
 * within the wall, and a garage door lifting into the room needs headroom above its head for its tracks or drum
 * (`OpeningHead::check()` takes it).
 */
final class OpeningClearances
{
	/** Depth of a frame set in from the outer face (`inset`), mm: the TS `InsetFrameDepth`. */
	public const int InsetFrameDepth = 80;

	/** Depth of a lift-and-slide door's frame, two tracks with their panes, mm: the TS `LiftSlideFrameDepth`. */
	public const int LiftSlideFrameDepth = 130;

	/** What a lifting garage door needs above its head under the wall's top, for the tracks or the drum, mm. */
	public const int GarageHeadroom = 200;

	/**
	 * Whether an opening's leaves stand in its frame, so the frame may be set in from the outer face: doors, windows and
	 * lift-and-slide doors (`$fixedPanes` > 0); not a passage, a niche, a leaf hung on or in the wall, or a garage door.
	 */
	public static function framed(OpeningTypeEnum $type, int $fixedPanes): bool
	{
		return match ($type) {
			OpeningTypeEnum::Passage, OpeningTypeEnum::Niche, OpeningTypeEnum::PocketDoor, OpeningTypeEnum::GarageDoor => false,
			OpeningTypeEnum::SlidingDoor => $fixedPanes > 0,
			OpeningTypeEnum::Door, OpeningTypeEnum::DoubleDoor, OpeningTypeEnum::Window => true,
		};
	}

	/** How deep (mm) the frame of an opening set in from the outer face is. */
	public static function frameDepth(OpeningTypeEnum $type, int $fixedPanes): int
	{
		return $type === OpeningTypeEnum::SlidingDoor && $fixedPanes > 0 ? self::LiftSlideFrameDepth : self::InsetFrameDepth;
	}

	/** A frame's `inset` (mm from the outer face) in a wall `wallThickness` thick, null when not given or not `framed()`. */
	public static function inset(OpeningTypeEnum $type, int $fixedPanes, ?int $inset, int $wallThickness): ?int
	{
		if ($inset === null || !self::framed($type, $fixedPanes)) {
			return null;
		}
		$depth = self::frameDepth($type, $fixedPanes);
		$max = $wallThickness - $depth;
		if ($inset < 0 || $inset > $max) {
			throw EditException::invalid($max < 0
				? sprintf('A frame set in from the face needs a wall at least %d mm thick; clear inset.', $depth)
				: sprintf('In this wall (%d mm thick) the frame is set in 0 to %d mm from the outer face.', $wallThickness, $max));
		}

		return $inset;
	}

	/** What an opening needs above its head under the wall's top, mm: a lifting garage door's `GarageHeadroom`. */
	public static function headroom(OpeningTypeEnum $type, ?GarageDoorStyleEnum $style): int
	{
		return $type === OpeningTypeEnum::GarageDoor && ($style ?? GarageDoorStyleEnum::Sectional)->lifts() ? self::GarageHeadroom : 0;
	}

	/** Whether an existing opening still fits a changed wall across it and above it. */
	public static function fit(Opening $opening, int $wallThickness, int $wallHeight): bool
	{
		return $opening->sillHeight + $opening->height + self::headroom($opening->type, $opening->garageDoorStyle) <= $wallHeight
			&& ($opening->inset === null || $opening->inset <= $wallThickness - self::frameDepth($opening->type, $opening->fixedPanes));
	}
}
