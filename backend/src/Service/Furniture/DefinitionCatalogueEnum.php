<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/**
 * Where a definition is filed in the editor's Add library (its optional `catalogue`, FD-107); the evaluators do not read
 * it. Without one the definition goes under Other.
 */
enum DefinitionCatalogueEnum: string
{
	/** Cabinets, worktops, splashbacks. */
	case Kitchen = 'kitchen';
	/** Wardrobes, shelves, sideboards, rails, pegs. */
	case Storage = 'storage';
	/** Chairs, sofas, benches, poufs. */
	case Seating = 'seating';
	/** Dining tables, desks. */
	case Tables = 'tables';
	/** Beds and bedside tables. */
	case Bedroom = 'bedroom';
	/** Baths, showers, vanities, mirrors. */
	case Bathroom = 'bathroom';
	/** Pendants, downlights, wall lights, light sources. */
	case Lighting = 'lighting';
	/** Sockets, switches, data sockets, plumbing points. */
	case Electrical = 'electrical';
	/** Pictures, rugs, books, curtains, blinds, cushions. */
	case Decor = 'decor';
	/** Built-in parts: panels, railings, a fireplace. */
	case Building = 'building';
	/** Sheds, bin stores, stepping slabs. */
	case Outdoor = 'outdoor';
	/** On a roof: vent tiles, roof steps, solar collectors, dishes. */
	case Roof = 'roof';

	/** @return list<string> */
	public static function values(): array
	{
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
