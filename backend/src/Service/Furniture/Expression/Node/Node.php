<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

/** A node of a parsed expression; `evaluate()` throws ExpressionException for unknown variables and type errors. */
interface Node
{
	/** @param array<string, float|bool|string> $variables */
	public function evaluate(array $variables): float|bool|string;
}
