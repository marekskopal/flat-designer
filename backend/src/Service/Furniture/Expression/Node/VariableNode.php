<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\ExpressionErrorEnum;
use FlatDesigner\Service\Furniture\Expression\ExpressionException;

final readonly class VariableNode implements Node
{
	public function __construct(private string $name)
	{
	}

	public function evaluate(array $variables): float|bool|string
	{
		if (!array_key_exists($this->name, $variables)) {
			throw new ExpressionException(ExpressionErrorEnum::UnknownVariable, sprintf('Unknown variable "%s".', $this->name));
		}

		return $variables[$this->name];
	}
}
