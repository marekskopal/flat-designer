<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** Reading the plain settings of a parameter definition (`min`, `max`, `options`, …). */
final readonly class ParameterInput
{
	public static function optionalInt(mixed $value, string $path): ?int
	{
		if ($value === null) {
			return null;
		}
		if (!DefinitionParameter::isWholeNumber($value)) {
			throw DefinitionException::invalid($path, 'Must be a whole number.');
		}

		return (int) $value;
	}

	/** @return list<string> */
	public static function options(mixed $options, string $path): array
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
