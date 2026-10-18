<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

use FlatDesigner\Service\Furniture\Expression\Node\Node;

/** A parsed expression (see `Parser`); evaluate it as often as needed with different variables. */
final readonly class Expression
{
	public function __construct(public string $source, private Node $root)
	{
	}

	/** @param array<string, float|bool|string> $variables */
	public function evaluate(array $variables): float|bool|string
	{
		return $this->root->evaluate($variables);
	}
}
