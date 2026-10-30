<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** A railing's handrail (FD-90): a round tube, a square bar, a flat cap, or none (a glass balustrade). */
enum HandrailProfileEnum: string
{
	case Round = 'Round';
	case Square = 'Square';
	case Flat = 'Flat';
	case None = 'None';
}
