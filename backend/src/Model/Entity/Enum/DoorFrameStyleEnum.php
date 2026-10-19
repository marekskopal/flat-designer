<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/**
 * Frame of a doorway: `Lining` slightly proud of both wall faces, `Architrave` a lining with casing boards on both
 * faces, `Flush` a hidden frame flush with the wall, `None` a bare hole.
 */
enum DoorFrameStyleEnum: string
{
	case Lining = 'Lining';
	case Architrave = 'Architrave';
	case Flush = 'Flush';
	case None = 'None';
}
