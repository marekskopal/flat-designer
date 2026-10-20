<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

enum ParameterTypeEnum: string
{
	/** Whole number, optionally limited by `min` / `max` (mm, counts). */
	case Int = 'int';
	case Bool = 'bool';
	/** One of the strings in `options`. */
	case Select = 'select';
	/** A list of records with the declared `fields` (each an int, bool or select), e.g. a cabinet's fronts. */
	case List = 'list';
	/**
	 * A picture: the id of an image file of the item's project, or null (none; also the default). `picture` parts show
	 * it through their `image` option; the item validator checks the file.
	 */
	case Image = 'image';
}
