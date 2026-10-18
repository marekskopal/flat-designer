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
}
