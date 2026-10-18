<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

/** `&&` / `||` of booleans; the right side is evaluated only when needed. */
final readonly class LogicalNode implements Node
{
	/** @param string $operator "&&" or "||" */
	public function __construct(private string $operator, private Node $left, private Node $right)
	{
	}

	public function evaluate(array $variables): bool
	{
		$context = sprintf('"%s"', $this->operator);
		$left = Values::boolean($this->left->evaluate($variables), $context);
		if ($left === ($this->operator === '||')) {
			return $left;
		}

		return Values::boolean($this->right->evaluate($variables), $context);
	}
}
