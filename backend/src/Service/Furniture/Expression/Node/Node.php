<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\RecordValue;

/**
 * A node of a parsed expression; `evaluate()` throws ExpressionException for unknown variables and type errors.
 *
 * @phpstan-import-type Value from Values
 */
interface Node
{
	/**
	 * @param array<string, Value> $variables
	 * @return Value
	 */
	public function evaluate(array $variables): float|bool|string|array|RecordValue|null;
}
