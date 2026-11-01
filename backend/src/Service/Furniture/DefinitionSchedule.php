<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/**
 * A definition's optional `schedule` settings for the electrical schedule (FD-64), beside its `category`; the evaluators
 * do not read them. `count` names an int parameter that multiplies the item (a socket's gangs, a data socket's ports),
 * and `values` the int, bool and select parameters that tell its points apart and that the schedule lists (null: all
 * of them). A definition without settings (older JSON: a version's snapshot, a share link's scene, a copy made before
 * them) counts a socket by an int `gangs` and a data socket by an int `ports`, as the schedule did before (`Defaults`).
 */
final readonly class DefinitionSchedule
{
	/** Parameter types the schedule lists. */
	public const array ValueTypes = ['int', 'bool', 'select'];

	private const array Keys = ['count', 'values'];

	/** category => the int parameter counted when a definition has no settings */
	public const array Defaults = ['socket' => 'gangs', 'data' => 'ports'];

	/** @param list<string>|null $values */
	public function __construct(public ?string $count = null, public ?array $values = null)
	{
	}

	/**
	 * The settings of a stored definition, anything malformed ignored.
	 *
	 * @param array<mixed> $definition
	 */
	public static function of(array $definition): self
	{
		$schedule = $definition['schedule'] ?? null;
		if (!is_array($schedule)) {
			return new self(count: self::defaultCount($definition));
		}
		$count = $schedule['count'] ?? null;
		$values = $schedule['values'] ?? null;

		return new self(
			count: is_string($count) ? $count : null,
			values: is_array($values) ? array_values(array_filter($values, 'is_string')) : null,
		);
	}

	/**
	 * Throws `DefinitionException` unless the settings come with a `category`, `count` names an int parameter and
	 * `values` lists distinct int, bool or select parameters.
	 *
	 * @param array<mixed> $definition
	 */
	public static function check(array $definition): void
	{
		if (!array_key_exists('schedule', $definition)) {
			return;
		}
		$schedule = self::shape($definition['schedule']);
		if (DefinitionCategoryEnum::of($definition) === null) {
			throw DefinitionException::invalid('schedule', 'Needs a category.');
		}
		$types = self::parameterTypes($definition);
		$count = $schedule['count'] ?? null;
		if ($count !== null && (!is_string($count) || ($types[$count] ?? null) !== 'int')) {
			throw DefinitionException::invalid('schedule.count', 'Must be the key of an int parameter.');
		}
		self::checkValues($schedule['values'] ?? null, $types);
	}

	/**
	 * The int parameter a definition without settings counts by: `Defaults` for its category, when it has one.
	 *
	 * @param array<mixed> $definition
	 */
	public static function defaultCount(array $definition): ?string
	{
		$category = DefinitionCategoryEnum::of($definition);
		$count = $category === null ? null : self::Defaults[$category->value] ?? null;

		return $count !== null && (self::parameterTypes($definition)[$count] ?? null) === 'int' ? $count : null;
	}

	/** @return array<mixed> the settings object, checked for unknown keys */
	private static function shape(mixed $schedule): array
	{
		if (!is_array($schedule) || array_is_list($schedule) && $schedule !== []) {
			throw DefinitionException::invalid('schedule', 'Must be an object {count?, values?}.');
		}
		foreach (array_keys($schedule) as $key) {
			if (!in_array($key, self::Keys, true)) {
				throw DefinitionException::invalid('schedule.' . $key, sprintf('Unknown key; known: %s.', implode(', ', self::Keys)));
			}
		}

		return $schedule;
	}

	/**
	 * @param array<mixed> $definition
	 * @return array<string, string> the parameters' types by key
	 */
	private static function parameterTypes(array $definition): array
	{
		$types = [];
		foreach (DefinitionValidator::list($definition, 'parameters') as $parameter) {
			if (is_string($parameter['key'] ?? null) && is_string($parameter['type'] ?? null)) {
				$types[$parameter['key']] = $parameter['type'];
			}
		}

		return $types;
	}

	/** @param array<string, string> $types */
	private static function checkValues(mixed $values, array $types): void
	{
		if ($values === null) {
			return;
		}
		if (!is_array($values) || !array_is_list($values)) {
			throw DefinitionException::invalid('schedule.values', 'Must be a list of parameter keys.');
		}
		foreach ($values as $index => $key) {
			if (!is_string($key) || !in_array($types[$key] ?? null, self::ValueTypes, true)) {
				throw DefinitionException::invalid(
					sprintf('schedule.values[%d]', $index),
					sprintf('Must be the key of a %s parameter.', implode(', ', self::ValueTypes)),
				);
			}
			if (array_search($key, $values, true) !== $index) {
				throw DefinitionException::invalid(sprintf('schedule.values[%d]', $index), 'Listed twice.');
			}
		}
	}
}
