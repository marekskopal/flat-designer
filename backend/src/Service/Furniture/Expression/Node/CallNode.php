<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\FunctionEnum;

final readonly class CallNode implements Node
{
	/** @param list<Node> $rest the arguments after the first, checked against the function's arity by the parser */
	public function __construct(private FunctionEnum $function, private Node $first, private array $rest)
	{
	}

	public function evaluate(array $variables): float
	{
		$context = sprintf('Function "%s"', $this->function->value);

		return $this->function->apply(
			Values::number($this->first->evaluate($variables), $context),
			array_map(static fn (Node $argument): float => Values::number($argument->evaluate($variables), $context), $this->rest),
		);
	}
}
