<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use stdClass;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A definition as stored: JSON with its objects kept objects even when empty (PHP decodes `{}` as `[]`): the size,
 * `derived`, every parameter (with its fields and the items of a list default), material slot and part (with its
 * `repeat` and an assembly's parts). An option holding an object other than `{"expr": …}` would need adding here (the
 * evaluators read `[]` as an empty object either way).
 */
final readonly class DefinitionEncoder
{
	/** @param array<mixed> $definition */
	public static function encode(array $definition): string
	{
		$object = self::object($definition);
		foreach (['size', 'derived'] as $key) {
			if (is_array($definition[$key] ?? null)) {
				$object->{$key} = self::object($definition[$key]);
			}
		}
		foreach (['parameters', 'materialSlots', 'parts'] as $key) {
			if (is_array($definition[$key] ?? null)) {
				$object->{$key} = array_map(self::entry(...), $definition[$key]);
			}
		}

		return json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/** A parameter, material slot or part (with its fields, default list items and assembly parts) as objects. */
	private static function entry(mixed $entry): mixed
	{
		if (!is_array($entry)) {
			return $entry;
		}
		$object = self::object($entry);
		if (is_array($entry['repeat'] ?? null)) {
			$object->repeat = self::object($entry['repeat']);
		}
		foreach (['fields', 'parts'] as $key) {
			if (is_array($entry[$key] ?? null)) {
				$object->{$key} = array_map(self::entry(...), $entry[$key]);
			}
		}
		if (($entry['type'] ?? null) === 'list' && is_array($entry['default'] ?? null)) {
			$object->default = array_map(
				static fn (mixed $item): mixed => is_array($item) ? self::object($item) : $item,
				$entry['default'],
			);
		}

		return $object;
	}

	/** @param array<mixed> $array */
	private static function object(array $array): stdClass
	{
		$object = new stdClass();
		foreach ($array as $key => $value) {
			$object->{$key} = $value;
		}

		return $object;
	}
}
