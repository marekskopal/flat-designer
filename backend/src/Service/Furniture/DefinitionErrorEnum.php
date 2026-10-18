<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** Why a definition could not be evaluated, besides expression errors (codes of tests/fixtures/furniture-definitions.json). */
enum DefinitionErrorEnum: string
{
	/** The definition itself is malformed. */
	case Invalid = 'invalid';
	/** An item's parameter value does not fit the parameter. */
	case InvalidValue = 'invalidValue';
	/** Too many part entries, repeats or parts. */
	case Limit = 'limit';
	/** A part comes out zero or negative in size (usually: the item is too small). */
	case NonPositiveSize = 'nonPositiveSize';
}
