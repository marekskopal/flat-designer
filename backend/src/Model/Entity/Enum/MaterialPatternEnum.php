<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

enum MaterialPatternEnum: string
{
	case Plain = 'Plain';
	case Wood = 'Wood';
	case Planks = 'Planks';
	case Tiles = 'Tiles';
	case Glass = 'Glass';
}
