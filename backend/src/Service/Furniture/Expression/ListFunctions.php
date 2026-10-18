<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

use FlatDesigner\Service\Furniture\Expression\Node\Values;

/**
 * The list functions of the expression language. `share` and `offsets` lay sizes out in a row: front heights from the
 * bottom up, where fronts without a height share what is left.
 *
 * @phpstan-import-type Value from Values
 */
final readonly class ListFunctions
{
	/** @param list<mixed> $list */
	public static function sum(array $list): float
	{
		return Values::finite(array_sum(array_map(static fn (mixed $item): float => Values::number($item, 'Function "sum"'), $list)));
	}

	/** @param list<mixed> $list */
	public static function contains(array $list, mixed $needle): bool
	{
		if (!Values::isScalar($needle)) {
			throw new ExpressionException(
				ExpressionErrorEnum::Type,
				sprintf('Function "contains" looks for a number, boolean, string or null, got %s.', Values::typeName($needle)),
			);
		}

		return array_any($list, static fn (mixed $item): bool => Values::isScalar($item) && $item === $needle);
	}

	/**
	 * @param list<mixed> $list of records
	 * @return list<Value>
	 */
	public static function field(array $list, string $field): array
	{
		return array_map(static fn (mixed $item): mixed => Values::record($item, 'Function "field"')->get($field), $list);
	}

	/**
	 * The sizes with each null replaced by an equal share of what the others and the gaps between them leave of `total`.
	 *
	 * @param list<mixed> $sizes numbers or null
	 * @return list<float>
	 */
	public static function share(array $sizes, float $total, float $gap): array
	{
		$given = 0.0;
		$open = 0;
		foreach ($sizes as $size) {
			if ($size === null) {
				$open++;
			} else {
				$given += Values::number($size, 'Function "share"');
			}
		}
		$each = Values::finite($open === 0 ? 0.0 : ($total - Values::finite($given) - $gap * max(count($sizes) - 1, 0)) / $open);

		return array_map(static fn (mixed $size): float => $size === null ? $each : Values::number($size, 'Function "share"'), $sizes);
	}

	/**
	 * Where each size starts when laid out in a row from 0 with `gap` between them.
	 *
	 * @param list<mixed> $sizes numbers
	 * @return list<float>
	 */
	public static function offsets(array $sizes, float $gap): array
	{
		$offsets = [];
		$position = 0.0;
		foreach ($sizes as $size) {
			$offsets[] = $position;
			$position = Values::finite($position + Values::number($size, 'Function "offsets"') + $gap);
		}

		return $offsets;
	}
}
