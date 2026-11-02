<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/** A roof's gutters along its eaves: none, half-round or box section. */
enum GutterProfileEnum: string
{
	case None = 'None';
	case HalfRound = 'HalfRound';
	case Box = 'Box';
}
