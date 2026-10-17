<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

enum WallTypeEnum: string
{
	case Exterior = 'Exterior';
	case Interior = 'Interior';
	case Shaft = 'Shaft';
}
