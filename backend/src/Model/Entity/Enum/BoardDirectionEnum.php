<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/** Which way the boards of a `Boarded` leaf run. */
enum BoardDirectionEnum: string
{
	case Vertical = 'Vertical';
	case Horizontal = 'Horizontal';
}
