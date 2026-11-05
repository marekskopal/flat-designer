<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture;

/** What a definition's `translations` may name: its parameters (`list.field`), selects' options, slots and part names. */
final readonly class DefinitionTextKeys
{
	/**
	 * @param array<mixed> $definition
	 * @return list<string>
	 */
	public static function parameters(array $definition): array
	{
		$keys = [];
		foreach (DefinitionValidator::list($definition, 'parameters') as $parameter) {
			$key = self::key($parameter);
			$keys[] = $key;
			foreach (DefinitionValidator::list($parameter, 'fields') as $field) {
				$keys[] = $key . '.' . self::key($field);
			}
		}

		return $keys;
	}

	/**
	 * Select parameters (and select fields of lists, `list.field`) by key, with their options.
	 *
	 * @param array<mixed> $definition
	 * @return array<string, list<string>>
	 */
	public static function selects(array $definition): array
	{
		$selects = [];
		$add = static function (array $parameter, string $key) use (&$selects): void {
			if (($parameter['type'] ?? null) === 'select' && is_array($parameter['options'] ?? null)) {
				$selects[$key] = array_values(
					array_map(static fn (mixed $o): string => is_string($o) ? $o : '', $parameter['options']),
				);
			}
		};
		foreach (DefinitionValidator::list($definition, 'parameters') as $parameter) {
			$key = self::key($parameter);
			$add($parameter, $key);
			foreach (DefinitionValidator::list($parameter, 'fields') as $field) {
				$add($field, $key . '.' . self::key($field));
			}
		}

		return $selects;
	}

	/**
	 * @param array<mixed> $definition
	 * @return list<string>
	 */
	public static function slots(array $definition): array
	{
		return array_map(
			static fn (array $slot): string => self::key($slot),
			DefinitionValidator::list($definition, 'materialSlots'),
		);
	}

	/**
	 * The names of the parts, an assembly's included.
	 *
	 * @param list<array<mixed>> $parts
	 * @return list<string>
	 */
	public static function parts(array $parts): array
	{
		$names = [];
		foreach ($parts as $part) {
			if (is_string($part['name'] ?? null)) {
				$names[] = $part['name'];
			}
			$names = [...$names, ...self::parts(DefinitionValidator::list($part, 'parts'))];
		}

		return $names;
	}

	/** @param array<mixed> $entry */
	private static function key(array $entry): string
	{
		return is_string($entry['key'] ?? null) ? $entry['key'] : '';
	}
}
