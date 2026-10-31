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
	/** Round-ended clay tiles in half-bond rows (FD-91): `tileWidth` wide and `tileHeight` long, a row showing 0.4 of the length. */
	case BeaverTail = 'BeaverTail';
	/** Random coursed dry-stone walling (FD-92): stones about `tileWidth` long in courses about `tileHeight` high; not laid in pieces. */
	case Stone = 'Stone';
	/**
	 * Cast concrete showing its formwork (FD-92): panels or boards `tileWidth` along the wall by `tileHeight` up it, with
	 * their joints; not laid in pieces.
	 */
	case Formwork = 'Formwork';
}
