<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\RecordValue;

/** `a ?? b`: b (evaluated only then) when a is null, otherwise a. */
final readonly class NullishNode implements Node
{
	public function __construct(private Node $left, private Node $right)
	{
	}

	public function evaluate(array $variables): float|bool|string|array|RecordValue|null
	{
		return $this->left->evaluate($variables) ?? $this->right->evaluate($variables);
	}
}
