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
	case Box = 'Box';
	case Bathtub = 'Bathtub';
	case Washbasin = 'Washbasin';
	case Toilet = 'Toilet';
	case WashingMachine = 'WashingMachine';
	case Mirror = 'Mirror';
	case TowelRadiator = 'TowelRadiator';
	case FlushPlate = 'FlushPlate';
	case Fixture = 'Fixture';
	case Fence = 'Fence';
	case Wardrobe = 'Wardrobe';
	case Chair = 'Chair';
	case Table = 'Table';
	/** Drawn from a furniture definition (`Item::$definition`). */
	case Custom = 'Custom';
	/** A bought product drawn from a 3D model of the library (`items.model_id`), scaled to fit the item's box. */
	case Model = 'Model';
}
