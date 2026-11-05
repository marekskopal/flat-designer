<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/**
 * Frame of a doorway: `Lining` slightly proud of both wall faces, `Architrave` a lining with casing boards on both
 * faces, `Flush` a hidden frame flush with the wall, `None` a bare hole, `Steel` a pressed steel frame wrapping the reveal
 * (the common frame of Czech flats), rebated like a lining.
 */
enum DoorFrameStyleEnum: string
{
	case Lining = 'Lining';
	case Architrave = 'Architrave';
	case Flush = 'Flush';
	case None = 'None';
	case Steel = 'Steel';
}
