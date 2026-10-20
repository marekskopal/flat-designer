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
	case Grass = 'Grass';
	case Gravel = 'Gravel';
	case Terrazzo = 'Terrazzo';
	case Brick = 'Brick';
	case Herringbone = 'Herringbone';
}
