<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** Handle of a door leaf; an opening without one takes its type's default (a lever, a pull on sliding leaves). */
enum DoorHandleEnum: string
{
	case Lever = 'Lever';
	case Knob = 'Knob';
	case Pull = 'Pull';
	case None = 'None';
}
