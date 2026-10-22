<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

enum ItemKindEnum: string
{
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
	/** A structural pillar from the floor, square or round (`params.shape`). */
	case Column = 'Column';
	/** A structural downstand beam along its width; z is its soffit. */
	case Beam = 'Beam';
	/** Drawn from a furniture definition (`Item::$definition`). */
	case Custom = 'Custom';
	/** A bought product drawn from a 3D model of the library (`items.model_id`), scaled to fit the item's box. */
	case Model = 'Model';

	/** Part of the building rather than furniture: cut by the section plane like walls. */
	public function isStructural(): bool
	{
		return $this === self::Column || $this === self::Beam;
	}
}
