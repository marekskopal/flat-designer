<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

enum WallTypeEnum: string
{
	case Exterior = 'Exterior';
	case Interior = 'Interior';
	case Shaft = 'Shaft';
}
