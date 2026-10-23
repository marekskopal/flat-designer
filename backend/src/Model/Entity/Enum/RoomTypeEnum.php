<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

enum RoomTypeEnum: string
{
	case Living = 'Living';
	case Bedroom = 'Bedroom';
	case Bathroom = 'Bathroom';
	case Toilet = 'Toilet';
	case Hallway = 'Hallway';
	case Terrace = 'Terrace';
	case Garden = 'Garden';
	case Path = 'Path';
	case Driveway = 'Driveway';

	/** Open-air rooms, summed apart from the indoor floor area in the room schedule. */
	public function isOutdoor(): bool
	{
		return in_array($this, [self::Terrace, self::Garden, self::Path, self::Driveway], true);
	}
}
