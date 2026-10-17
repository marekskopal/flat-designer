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
}
