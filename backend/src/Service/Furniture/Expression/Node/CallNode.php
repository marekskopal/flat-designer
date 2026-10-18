<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\FunctionEnum;

final readonly class CallNode implements Node
{
	/** @param list<Node> $arguments checked against the function's arity by the parser */
	public function __construct(private FunctionEnum $function, private array $arguments)
	{
	}

	/** @return float|bool|list<mixed> */
	public function evaluate(array $variables): float|bool|array
	{
		return $this->function->apply(array_map(static fn (Node $argument): mixed => $argument->evaluate($variables), $this->arguments));
	}
}
