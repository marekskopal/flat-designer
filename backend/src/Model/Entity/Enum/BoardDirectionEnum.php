<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** Which way the boards of a `Boarded` leaf run (FD-89). */
enum BoardDirectionEnum: string
{
	case Vertical = 'Vertical';
	case Horizontal = 'Horizontal';
}
