<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

use FlatDesigner\Service\Furniture\Expression\Node\Node;
use FlatDesigner\Service\Furniture\Expression\Node\Values;

/**
 * A parsed expression (see `Parser`); evaluate it as often as needed with different variables.
 *
 * @phpstan-import-type Value from Values
 */
final readonly class Expression
{
	public function __construct(public string $source, private Node $root)
	{
	}

	/**
	 * @param array<string, Value> $variables
	 * @return Value
	 */
	public function evaluate(array $variables): float|bool|string|array|RecordValue|null
	{
		return $this->root->evaluate($variables);
	}
}
