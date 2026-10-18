<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\ExpressionErrorEnum;
use FlatDesigner\Service\Furniture\Expression\ExpressionException;

/** Type checks shared by the nodes: there are no implicit conversions between numbers, booleans and strings. */
final readonly class Values
{
	public static function number(float|bool|string $value, string $context): float
	{
		if (!is_float($value)) {
			throw self::typeError($value, 'a number', $context);
		}

		return $value;
	}

	public static function boolean(float|bool|string $value, string $context): bool
	{
		if (!is_bool($value)) {
			throw self::typeError($value, 'true or false', $context);
		}

		return $value;
	}

	public static function typeName(float|bool|string $value): string
	{
		return match (true) {
			is_float($value) => 'a number',
			is_bool($value) => 'a boolean',
			default => 'a string',
		};
	}

	public static function finite(float $value): float
	{
		if (!is_finite($value)) {
			throw new ExpressionException(ExpressionErrorEnum::NonFinite, 'Result is too large.');
		}

		return $value;
	}

	private static function typeError(float|bool|string $value, string $expected, string $context): ExpressionException
	{
		return new ExpressionException(
			ExpressionErrorEnum::Type,
			sprintf('%s needs %s, got %s.', $context, $expected, self::typeName($value)),
		);
	}
}
