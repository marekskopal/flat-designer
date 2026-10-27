<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** How a stair is built: a solid (concrete) flight, treads between stringers, or open treads on a spine. */
enum StairConstructionEnum: string
{
	case Solid = 'Solid';
	case Stringers = 'Stringers';
	case Open = 'Open';
}
