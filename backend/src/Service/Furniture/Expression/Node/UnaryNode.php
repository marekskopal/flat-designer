<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

final readonly class UnaryNode implements Node
{
	/** @param string $operator "-" or "!" */
	public function __construct(private string $operator, private Node $operand)
	{
	}

	public function evaluate(array $variables): float|bool
	{
		$value = $this->operand->evaluate($variables);

		return $this->operator === '-'
			? -Values::number($value, 'Unary "-"')
			: !Values::boolean($value, '"!"');
	}
}
