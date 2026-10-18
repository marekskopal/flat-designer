<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** A parameter of a furniture definition: what an item may set in `params.values`. */
final readonly class DefinitionParameter
{
	/** @param list<string> $options */
	public function __construct(
		public string $key,
		public ParameterTypeEnum $type,
		public float|bool|string $default,
		public ?int $min = null,
		public ?int $max = null,
		public array $options = [],
	) {
	}

	/**
	 * Reads `parameters[n]` of a definition.
	 *
	 * @param array<string, mixed> $taken parameters read so far
	 */
	public static function read(mixed $definition, string $path, array $taken): self
	{
		if (!is_array($definition)) {
			throw DefinitionException::invalid($path, 'Must be an object.');
		}
		$key = Names::free($definition['key'] ?? null, $taken);
		if ($key === null) {
			throw DefinitionException::invalid(
				$path . '.key',
				'Must be a unique name (letters, digits, _), not W, D, H, true, false or null.',
			);
		}
		$type = is_string($definition['type'] ?? null) ? ParameterTypeEnum::tryFrom($definition['type']) : null;
		if ($type === null) {
			throw DefinitionException::invalid($path . '.type', 'Must be "int", "bool" or "select".');
		}

		$parameter = new self(
			$key,
			$type,
			false,
			self::optionalInt($definition['min'] ?? null, $path . '.min'),
			self::optionalInt($definition['max'] ?? null, $path . '.max'),
			$type === ParameterTypeEnum::Select ? self::options($definition['options'] ?? null, $path . '.options') : [],
		);
		$default = $parameter->accept($definition['default'] ?? null);
		if ($default === null) {
			throw DefinitionException::invalid($path . '.default', sprintf('Must be %s.', $parameter->describe()));
		}

		return new self($key, $type, $default, $parameter->min, $parameter->max, $parameter->options);
	}

	/** The value as an expression variable, or null when it does not fit (wrong type, out of range, not an option). */
	public function accept(mixed $value): float|bool|string|null
	{
		return match ($this->type) {
			ParameterTypeEnum::Int => self::isWholeNumber($value)
				&& ($this->min === null || $value >= $this->min)
				&& ($this->max === null || $value <= $this->max) ? (float) $value : null,
			ParameterTypeEnum::Bool => is_bool($value) ? $value : null,
			ParameterTypeEnum::Select => is_string($value) && in_array($value, $this->options, true) ? $value : null,
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
		};
	}

	/** @phpstan-assert-if-true int|float $value */
	public static function isWholeNumber(mixed $value): bool
	{
		return is_int($value) || is_float($value) && is_finite($value) && floor($value) === $value;
	}

	private static function optionalInt(mixed $value, string $path): ?int
	{
		if ($value === null) {
			return null;
		}
		if (!self::isWholeNumber($value)) {
			throw DefinitionException::invalid($path, 'Must be a whole number.');
		}

		return (int) $value;
	}

	/** @return list<string> */
	private static function options(mixed $options, string $path): array
	{
		if (!is_array($options) || $options === [] || !array_is_list($options)) {
			throw DefinitionException::invalid($path, 'Must be a non-empty list of strings.');
		}

		$strings = [];
		foreach ($options as $option) {
			if (!is_string($option)) {
				throw DefinitionException::invalid($path, 'Must be a non-empty list of strings.');
			}
			$strings[] = $option;
		}

		return $strings;
	}
}
