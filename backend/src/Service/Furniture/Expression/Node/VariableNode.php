<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture\Expression\Node;

use Izbik\Service\Furniture\Expression\ExpressionErrorEnum;
use Izbik\Service\Furniture\Expression\ExpressionException;
use Izbik\Service\Furniture\Expression\RecordValue;

final readonly class VariableNode implements Node
{
	public function __construct(private string $name)
	{
	}

	public function evaluate(array $variables): float|bool|string|array|RecordValue|null
	{
		if (!array_key_exists($this->name, $variables)) {
			throw new ExpressionException(ExpressionErrorEnum::UnknownVariable, sprintf('Unknown variable "%s".', $this->name));
		}

		return $variables[$this->name];
	}
}
