<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

enum ItemKindEnum: string
{
	case BaseCabinet = 'BaseCabinet';
	case WallCabinet = 'WallCabinet';
	case TallCabinet = 'TallCabinet';
	case Worktop = 'Worktop';
	case Backsplash = 'Backsplash';
	case Sink = 'Sink';
	case Hob = 'Hob';
	case LedStrip = 'LedStrip';
}
