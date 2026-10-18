<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\ExpressionErrorEnum;
use FlatDesigner\Service\Furniture\Expression\ExpressionException;
use FlatDesigner\Service\Furniture\Expression\RecordValue;

/** `list[i]`: the item at a whole index from 0. */
final readonly class IndexNode implements Node
{
	public function __construct(private Node $target, private Node $index)
	{
	}

	public function evaluate(array $variables): float|bool|string|array|RecordValue|null
	{
		$list = Values::list($this->target->evaluate($variables), '"[ ]"');
		$index = Values::number($this->index->evaluate($variables), 'An index');
		if (floor($index) !== $index || $index < 0 || $index >= count($list)) {
			throw new ExpressionException(
				ExpressionErrorEnum::Index,
				sprintf('Index %s is not within the list (%d items).', $index, count($list)),
			);
		}

		/** @var float|bool|string|list<mixed>|RecordValue|null $item a value, as the lists hold only values */
		$item = $list[(int) $index];

		return $item;
	}
}
