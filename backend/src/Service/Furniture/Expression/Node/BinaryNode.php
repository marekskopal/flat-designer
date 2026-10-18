<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\ExpressionErrorEnum;
use FlatDesigner\Service\Furniture\Expression\ExpressionException;

/** Arithmetic and comparisons of numbers; `==` / `!=` compare two values of the same type, or anything with null. */
final readonly class BinaryNode implements Node
{
	public function __construct(private string $operator, private Node $left, private Node $right)
	{
	}

	public function evaluate(array $variables): float|bool
	{
		$left = $this->left->evaluate($variables);
		$right = $this->right->evaluate($variables);
		$context = sprintf('"%s"', $this->operator);
		if ($this->operator === '==' || $this->operator === '!=') {
			return Values::equals($left, $right, $context) === ($this->operator === '==');
		}

		$a = Values::number($left, $context);
		$b = Values::number($right, $context);
		if (($this->operator === '/' || $this->operator === '%') && $b === 0.0) {
			throw new ExpressionException(ExpressionErrorEnum::DivisionByZero, sprintf('"%s" by zero.', $this->operator));
		}

		return match ($this->operator) {
			'+' => Values::finite($a + $b),
			'-' => Values::finite($a - $b),
			'*' => Values::finite($a * $b),
			'/' => Values::finite($a / $b),
			'%' => fmod($a, $b),
			'<' => $a < $b,
			'<=' => $a <= $b,
			'>' => $a > $b,
			default => $a >= $b,
		};
	}
}
