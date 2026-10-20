<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** Reading the plain settings of a parameter definition (`min`, `max`, `options`, …). */
final readonly class ParameterInput
{
	/**
	 * The `type` of a parameter, or with `$field` of a field of a list parameter (which cannot be a list or an image).
	 *
	 * @param array<mixed> $definition
	 */
	public static function type(array $definition, string $path, bool $field): ParameterTypeEnum
	{
		$type = is_string($definition['type'] ?? null) ? ParameterTypeEnum::tryFrom($definition['type']) : null;
		if ($type === null || $field && in_array($type, [ParameterTypeEnum::List, ParameterTypeEnum::Image], true)) {
			throw DefinitionException::invalid(
				$path . '.type',
				$field
					? 'Must be "int", "bool" or "select" (a field cannot be a list or an image).'
					: 'Must be "int", "bool", "select", "list" or "image".',
			);
		}
		if ($type === ParameterTypeEnum::Image && ($definition['default'] ?? null) !== null) {
			throw DefinitionException::invalid($path . '.default', 'An image parameter has no default (null): the item picks a file.');
		}

		return $type;
	}

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
