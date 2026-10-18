<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\RecordValue;

/**
 * The value of a list parameter: a list of objects, each checked against the parameter's fields into a record (a
 * missing field takes its default, an unknown one is an error).
 */
final readonly class ListValues
{
	/** @return list<RecordValue> */
	public static function read(DefinitionParameter $parameter, mixed $value, string $path, DefinitionErrorEnum $error): array
	{
		$items = is_array($value) && array_is_list($value) ? $value : null;
		$count = $items === null ? -1 : count($items);
		if ($items === null || $count < ($parameter->minItems ?? 0) || $parameter->maxItems !== null && $count > $parameter->maxItems) {
			throw new DefinitionException($error, $path, sprintf('Must be %s.', $parameter->describe()));
		}
		if ($count > DefinitionEvaluator::MaxRepeat) {
			throw new DefinitionException(DefinitionErrorEnum::Limit, $path, sprintf('At most %d items.', DefinitionEvaluator::MaxRepeat));
		}

		$records = [];
		foreach ($items as $index => $item) {
			$records[] = self::record($parameter, $item, sprintf('%s[%d]', $path, $index), $error);
		}

		return $records;
	}

	private static function record(DefinitionParameter $parameter, mixed $item, string $path, DefinitionErrorEnum $error): RecordValue
	{
		if (!is_array($item) || $item !== [] && array_is_list($item)) {
			throw new DefinitionException($error, $path, 'Must be an object.');
		}

		$fields = [];
		foreach ($parameter->fields as $field) {
			$fields[$field->key] = array_key_exists($field->key, $item)
				? $field->value($item[$field->key], sprintf('%s.%s', $path, $field->key), $error)
				: $field->default;
		}
		foreach (array_keys($item) as $key) {
			if (!array_key_exists((string) $key, $fields)) {
				throw new DefinitionException($error, sprintf('%s.%s', $path, $key), sprintf('Unknown field "%s".', $key));
			}
		}

		return new RecordValue($fields);
	}
}
