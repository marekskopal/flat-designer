<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\RecordValue;

final readonly class ConditionalNode implements Node
{
	public function __construct(private Node $condition, private Node $then, private Node $else)
	{
	}

	public function evaluate(array $variables): float|bool|string|array|RecordValue|null
	{
		return Values::boolean($this->condition->evaluate($variables), '"? :"')
			? $this->then->evaluate($variables)
			: $this->else->evaluate($variables);
	}
}
