<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\Node\Values;
use FlatDesigner\Service\Furniture\Expression\RecordValue;

/**
 * A parameter of a furniture definition: what an item may set in `params.values`. A list parameter holds records of
 * its `fields`, which are read like parameters (but cannot be lists) and may be `optional` (null when not given).
 *
 * @phpstan-import-type Value from Values
 */
final readonly class DefinitionParameter
{
	/**
	 * @param Value $default
	 * @param list<string> $options
	 * @param list<self> $fields of a list parameter
	 */
	public function __construct(
		public string $key,
		public ParameterTypeEnum $type,
		public mixed $default,
		public ?int $min = null,
		public ?int $max = null,
		public array $options = [],
		public array $fields = [],
		public bool $optional = false,
		public ?int $minItems = null,
		public ?int $maxItems = null,
	) {
	}

	/**
	 * Reads `parameters[n]` of a definition, or with `$field` a field of a list parameter.
	 *
	 * @param array<string, mixed> $taken parameters (fields) read so far
	 */
	public static function read(mixed $definition, string $path, array $taken, bool $field = false): self
	{
		if (!is_array($definition)) {
			throw DefinitionException::invalid($path, 'Must be an object.');
		}
		$key = Names::free($definition['key'] ?? null, $taken);
		if ($key === null) {
			throw DefinitionException::invalid($path . '.key', Names::Rule);
		}
		$type = is_string($definition['type'] ?? null) ? ParameterTypeEnum::tryFrom($definition['type']) : null;
		if ($type === null || $field && $type === ParameterTypeEnum::List) {
			throw DefinitionException::invalid(
				$path . '.type',
				$field ? 'Must be "int", "bool" or "select" (a field cannot be a list).' : 'Must be "int", "bool", "select" or "list".',
			);
		}

		$parameter = new self(
			key: $key,
			type: $type,
			default: null,
			min: ParameterInput::optionalInt($definition['min'] ?? null, $path . '.min'),
			max: ParameterInput::optionalInt($definition['max'] ?? null, $path . '.max'),
			options: $type === ParameterTypeEnum::Select ? ParameterInput::options($definition['options'] ?? null, $path . '.options') : [],
			fields: $type === ParameterTypeEnum::List ? self::fields($definition['fields'] ?? null, $path . '.fields') : [],
			optional: $field && ($definition['optional'] ?? false) === true,
			minItems: ParameterInput::optionalInt($definition['minItems'] ?? null, $path . '.minItems'),
			maxItems: ParameterInput::optionalInt($definition['maxItems'] ?? null, $path . '.maxItems'),
		);
		$default = array_key_exists('default', $definition) || !$parameter->optional
			? $parameter->value($definition['default'] ?? null, $path . '.default', DefinitionErrorEnum::Invalid)
			: null;

		return clone($parameter, ['default' => $default]);
	}

	/**
	 * The value as an expression variable; throws `$error` at `$path` (or the item / field in it) when it does not fit.
	 *
	 * @return float|bool|string|list<RecordValue>|null
	 */
	public function value(mixed $value, string $path, DefinitionErrorEnum $error): float|bool|string|array|null
	{
		if ($this->type === ParameterTypeEnum::List) {
			return ListValues::read($this, $value, $path, $error);
		}
		if ($value === null && $this->optional) {
			return null;
		}

		return $this->accept($value) ?? throw new DefinitionException($error, $path, sprintf('Must be %s.', $this->describe()));
	}

	/** A single value (not a list) as an expression variable, or null when it does not fit. */
	public function accept(mixed $value): float|bool|string|null
	{
		return match ($this->type) {
			ParameterTypeEnum::Int => self::isWholeNumber($value)
				&& ($this->min === null || $value >= $this->min)
				&& ($this->max === null || $value <= $this->max) ? (float) $value : null,
			ParameterTypeEnum::Bool => is_bool($value) ? $value : null,
			ParameterTypeEnum::Select => is_string($value) && in_array($value, $this->options, true) ? $value : null,
			ParameterTypeEnum::List => null,
		};
	}

	public function describe(): string
	{
		return match ($this->type) {
			ParameterTypeEnum::Int => sprintf(
				'a whole number%s%s',
				$this->min === null ? '' : sprintf(' from %d', $this->min),
				$this->max === null ? '' : sprintf(' up to %d', $this->max),
			),
			ParameterTypeEnum::Bool => 'true or false',
			ParameterTypeEnum::Select => sprintf('one of "%s"', implode('", "', $this->options)),
			ParameterTypeEnum::List => sprintf(
				'a list of %s{%s}',
				$this->minItems === null && $this->maxItems === null ? '' : sprintf(
					'%d to %s ',
					$this->minItems ?? 0,
					$this->maxItems ?? 'any',
				),
				implode(', ', array_map(static fn (self $field): string => $field->key, $this->fields)),
			),
		};
	}

	/** @phpstan-assert-if-true int|float $value */
	public static function isWholeNumber(mixed $value): bool
	{
		return is_int($value) || is_float($value) && is_finite($value) && floor($value) === $value;
	}

	/** @return list<self> */
	private static function fields(mixed $definitions, string $path): array
	{
		if (!is_array($definitions) || $definitions === [] || !array_is_list($definitions)) {
			throw DefinitionException::invalid($path, 'Must be a non-empty list of fields.');
		}

		$fields = [];
		foreach ($definitions as $index => $definition) {
			$field = self::read($definition, sprintf('%s[%d]', $path, $index), $fields, true);
			$fields[$field->key] = $field;
		}

		return array_values($fields);
	}
}
