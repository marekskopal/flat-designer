<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\RecordValue;

/** `record.field`. */
final readonly class MemberNode implements Node
{
	public function __construct(private Node $target, private string $field)
	{
	}

	public function evaluate(array $variables): float|bool|string|array|RecordValue|null
	{
		return Values::record($this->target->evaluate($variables), sprintf('".%s"', $this->field))->get($this->field);
	}
}
