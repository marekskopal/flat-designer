<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

use FlatDesigner\Service\Furniture\Expression\Node\Values;

/**
 * A record of the expression language (an item of a list parameter, e.g. a front `{type, height}`); its fields are
 * read with `record.field`.
 *
 * @phpstan-import-type Value from Values
 */
final readonly class RecordValue
{
	/** @param array<string, Value> $fields */
	public function __construct(public array $fields)
	{
	}

	/** @return Value */
	public function get(string $field): mixed
	{
		if (!array_key_exists($field, $this->fields)) {
			throw new ExpressionException(ExpressionErrorEnum::UnknownField, sprintf('No field "%s".', $field));
		}

		return $this->fields[$field];
	}
}
