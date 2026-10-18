<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

final readonly class LiteralNode implements Node
{
	public function __construct(private float|bool|string $value)
	{
	}

	public function evaluate(array $variables): float|bool|string
	{
		return $this->value;
	}
}
