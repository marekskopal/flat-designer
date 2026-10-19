<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** Exterior shutters of a window, two leaves hinged on the outer face: `Louvred` slats in a frame, `Solid` boards. */
enum ShutterStyleEnum: string
{
	case Louvred = 'Louvred';
	case Solid = 'Solid';
}
