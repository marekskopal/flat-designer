<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Server;

use Mcp\Capability\Discovery\SchemaValidator;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * The SDK decodes tool arguments into PHP arrays, so an empty JSON object (`"params": {}`, or no arguments at all)
 * arrives as `[]` and its validator rejects it against `type: object`. This walks the schema and
 * turns `[]` back into an object wherever the schema expects an object and not a list. Only the validated copy
 * changes: the tool still gets `[]` for its `?array` parameter.
 */
final class EmptyObjectSchemaValidator extends SchemaValidator
{
	/**
	 * @param array<string, mixed>|object $schema
	 * @return list<array{pointer: string, keyword: string, message: string}>
	 */
	public function validateAgainstJsonSchema(mixed $data, array|object $schema): array
	{
		$schemaArray = is_array($schema) ? $schema : json_decode(json_encode($schema, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

		return parent::validateAgainstJsonSchema(is_array($schemaArray) ? self::restoreEmptyObjects($data, $schemaArray) : $data, $schema);
	}

	/** @param array<mixed> $schema */
	private static function restoreEmptyObjects(mixed $data, array $schema): mixed
	{
		if (!is_array($data)) {
			return $data;
		}

		if ($data === []) {
			return self::allows($schema, 'object') && !self::allows($schema, 'array') ? new stdClass() : $data;
		}

		if (array_is_list($data)) {
			$items = $schema['items'] ?? null;

			return is_array($items) ? array_map(static fn (mixed $item): mixed => self::restoreEmptyObjects($item, $items), $data) : $data;
		}

		$properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
		$additional = is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : null;
		foreach ($data as $key => $value) {
			$propertySchema = is_array($properties[$key] ?? null) ? $properties[$key] : $additional;
			if ($propertySchema !== null) {
				$data[$key] = self::restoreEmptyObjects($value, $propertySchema);
			}
		}

		return $data;
	}

	/** @param array<mixed> $schema */
	private static function allows(array $schema, string $type): bool
	{
		$types = $schema['type'] ?? null;

		return $types === $type || (is_array($types) && in_array($type, $types, true));
	}
}
