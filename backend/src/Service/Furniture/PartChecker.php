<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use const ARRAY_FILTER_USE_BOTH;

/**
 * The validator's checks of one part entry (and an assembly's parts): a known type, its name, its options (literal
 * ones by value, `{"expr": …}` ones by key; their values are checked once evaluated, in `PartOptions`).
 */
final readonly class PartChecker
{
	/** @param array<mixed> $part */
	public static function check(array $part, string $path): void
	{
		$type = PartTypeEnum::tryFrom(is_string($part['type'] ?? null) ? $part['type'] : '');
		if ($type === null) {
			throw DefinitionException::invalid(
				$path . '.type',
				sprintf('Unknown part type; one of "%s".', implode('", "', PartTypeEnum::values())),
			);
		}

		DefinitionValidator::checkLabel($part, 'name', $path);
		if ($type === PartTypeEnum::Assembly) {
			foreach (DefinitionValidator::list($part, 'parts') as $index => $child) {
				self::check($child, sprintf('%s.parts[%d]', $path, $index));
			}
		}
		$options = array_filter(
			$part,
			static fn (mixed $value, int|string $key): bool => !in_array($key, PartEntry::Keys, true)
				&& !($type === PartTypeEnum::Assembly && $key === 'parts'),
			ARRAY_FILTER_USE_BOTH,
		);
		self::checkOptions($type, $options, $path);
	}

	/**
	 * Literal options by value; `{"expr": …}` ones by key only, their value is checked once evaluated (`PartOptions`,
	 * which also checks options naming a material slot).
	 *
	 * @param array<mixed> $options
	 */
	private static function checkOptions(PartTypeEnum $type, array $options, string $path): void
	{
		foreach ($options as $key => $value) {
			$key = (string) $key;
			$error = PartOptions::isExpression($value) ? $type->checkOptionKey($key) : $type->checkOption($key, $value);
			if ($error !== null) {
				throw DefinitionException::invalid(sprintf('%s.%s', $path, $key), $error);
			}
		}
	}
}
