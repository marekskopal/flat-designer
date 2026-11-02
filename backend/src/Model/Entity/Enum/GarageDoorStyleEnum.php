<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/**
 * How a garage door opens: `Sectional` horizontal panels running up and back under the ceiling on tracks,
 * `UpAndOver` one leaf tilting up to lie under the ceiling, `SideHinged` two leaves hinged on the jambs, `Roller` a
 * curtain of slats rolling up into a drum above the opening.
 */
enum GarageDoorStyleEnum: string
{
	case Sectional = 'Sectional';
	case UpAndOver = 'UpAndOver';
	case SideHinged = 'SideHinged';
	case Roller = 'Roller';

	/** Whether the door lifts up into the room (all but the side-hinged), needing headroom above the opening. */
	public function lifts(): bool
	{
		return $this !== self::SideHinged;
	}
}
