<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

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
	/** Round-ended clay tiles in half-bond rows: `tileWidth` wide and `tileHeight` long, a row showing 0.4 of the length. */
	case BeaverTail = 'BeaverTail';
	/** Random coursed dry-stone walling: stones about `tileWidth` long in courses about `tileHeight` high; not laid in pieces. */
	case Stone = 'Stone';
	/**
	 * Cast concrete showing its formwork: panels or boards `tileWidth` along the wall by `tileHeight` up it, with
	 * their joints; not laid in pieces.
	 */
	case Formwork = 'Formwork';
	/** Veined stone in slabs or tiles `tileWidth` × `tileHeight`, the veins in the material's `accent`; laid in pieces. */
	case Marble = 'Marble';
	/** Parquet planks `tileWidth` long and `tileHeight` wide, their ends cut at 45° to meet in a zigzag; laid in pieces. */
	case Chevron = 'Chevron';
	/** Handmade glazed tiles `tileWidth` × `tileHeight`, each its own shade, with uneven edges; laid in pieces. */
	case Zellige = 'Zellige';
	/** Cement tiles `tileWidth` × `tileHeight` with a motif in the material's `accent`; laid in pieces. */
	case Encaustic = 'Encaustic';
	/** A panel of rounded flutes `tileWidth` apart running up it, whatever its grain; `tileHeight` is not read; not laid in pieces. */
	case Fluted = 'Fluted';
}
