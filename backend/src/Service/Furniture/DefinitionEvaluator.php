<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\Node\Values;

/**
 * Turns a furniture definition (decoded JSON, format 1, see docs/furniture-definitions-plan.md) and an item's size and
 * parameter values into its parts. Twin: frontend/src/app/viewer/engine/custom/definition.ts, both checked against
 * tests/fixtures/furniture-definitions.json.
 *
 * Variables: `W`, `D`, `H` (the item size), the parameters (item value or default; a list parameter gives a list of
 * records), then `derived` in order, and inside a repeated part its index or item (see `PartEntry`). For each part
 * entry: its copies (one without `repeat`), each kept when `when` is true; `x y z w h d` are numbers or expressions,
 * rounded half away from zero to mm, and `w h d` must come out positive; an `assembly` makes no part itself, its
 * parts are placed in its box. Only what the evaluation reads is checked here (part types, material codes
 * and the like are the validator's business).
 *
 * @phpstan-import-type Value from Values
 */
final readonly class DefinitionEvaluator
{
	public const int Format = 1;

	public const int MaxPartEntries = 200;

	public const int MaxParts = 500;

	public const int MaxRepeat = 100;

	/**
	 * @param array<mixed> $definition
	 * @param array<mixed> $values the item's parameter values (`params.values`)
	 * @return list<ResolvedPart>
	 */
	public function evaluate(array $definition, int $width, int $depth, int $height, array $values = []): array
	{
		if (($definition['format'] ?? null) !== self::Format) {
			throw DefinitionException::invalid('format', sprintf('Unsupported definition format; expected %d.', self::Format));
		}

		$variables = ['W' => (float) $width, 'D' => (float) $depth, 'H' => (float) $height];
		$variables += self::values(self::parameters($definition['parameters'] ?? []), $values);
		$variables = self::derived($definition['derived'] ?? [], $variables);
		$slots = self::materialSlots($definition['materialSlots'] ?? []);

		return self::parts($definition['parts'] ?? null, $variables, $slots);
	}

	/** @return array<string, DefinitionParameter> */
	public static function parameters(mixed $definitions): array
	{
		if (!is_array($definitions) || !array_is_list($definitions)) {
			throw DefinitionException::invalid('parameters', 'Must be a list.');
		}

		$parameters = [];
		foreach ($definitions as $index => $definition) {
			$parameter = DefinitionParameter::read($definition, sprintf('parameters[%d]', $index), $parameters);
			$parameters[$parameter->key] = $parameter;
		}

		return $parameters;
	}

	/**
	 * @param array<string, DefinitionParameter> $parameters
	 * @param array<mixed> $values
	 * @return array<string, Value>
	 */
	private static function values(array $parameters, array $values): array
	{
		if ($values !== [] && array_is_list($values)) {
			throw new DefinitionException(DefinitionErrorEnum::InvalidValue, 'values', 'Must be an object.');
		}

		$variables = [];
		foreach ($values as $key => $value) {
			$path = sprintf('values.%s', $key);
			$parameter = $parameters[$key] ?? null;
			if ($parameter === null) {
				throw new DefinitionException(DefinitionErrorEnum::InvalidValue, $path, sprintf('Unknown parameter "%s".', $key));
			}
			$variables[$parameter->key] = $parameter->value($value, $path, DefinitionErrorEnum::InvalidValue);
		}

		foreach ($parameters as $key => $parameter) {
			if (!array_key_exists($key, $variables)) {
				$variables[$key] = $parameter->default;
			}
		}

		return $variables;
	}

	/**
	 * @param array<string, Value> $variables
	 * @return array<string, Value>
	 */
	private static function derived(mixed $derived, array $variables): array
	{
		if (!is_array($derived) || $derived !== [] && array_is_list($derived)) {
			throw DefinitionException::invalid('derived', 'Must be an object of name: expression.');
		}

		foreach ($derived as $key => $source) {
			$path = sprintf('derived.%s', $key);
			$name = Names::free($key, $variables);
			if ($name === null) {
				throw DefinitionException::invalid($path, Names::Rule . ' A parameter\'s name is taken too.');
			}
			$variables[$name] = Expressions::run(Expressions::compile($source, $path), $variables, $path);
		}

		return $variables;
	}

	/** @return list<string> */
	private static function materialSlots(mixed $slots): array
	{
		if (!is_array($slots) || !array_is_list($slots)) {
			throw DefinitionException::invalid('materialSlots', 'Must be a list.');
		}

		$keys = [];
		foreach ($slots as $index => $slot) {
			$key = is_array($slot) ? ($slot['key'] ?? null) : null;
			if (!Names::isName($key) || in_array($key, $keys, true)) {
				throw DefinitionException::invalid(sprintf('materialSlots[%d].key', $index), 'Must be a unique name (letters, digits, _).');
			}
			$keys[] = $key;
		}

		return $keys;
	}

	/**
	 * @param array<string, Value> $variables
	 * @param list<string> $slots
	 * @return list<ResolvedPart>
	 */
	private static function parts(mixed $entries, array $variables, array $slots): array
	{
		if (!is_array($entries) || !array_is_list($entries)) {
			throw DefinitionException::invalid('parts', 'Must be a list.');
		}
		if (count($entries) > self::MaxPartEntries) {
			throw new DefinitionException(DefinitionErrorEnum::Limit, 'parts', sprintf('At most %d part entries.', self::MaxPartEntries));
		}

		$collector = new PartCollector();
		foreach ($entries as $index => $data) {
			$collector->entry('parts');
			PartEntry::read($data, sprintf('parts[%d]', $index), $slots, 1, $collector)->resolve($variables, [0, 0, 0], $collector);
		}

		return $collector->parts();
	}
}
