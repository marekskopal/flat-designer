<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression\Node;

use FlatDesigner\Service\Furniture\Expression\ExpressionErrorEnum;
use FlatDesigner\Service\Furniture\Expression\ExpressionException;
use FlatDesigner\Service\Furniture\Expression\RecordValue;
use stdClass;

/**
 * Values of the expression language: numbers (always float), booleans, strings, null, lists (PHP lists of values) and
 * records (`RecordValue`). There are no implicit conversions; null fails anything but `??`, `== / != null` and
 * `contains` (error `null`, other wrong types `type`).
 *
 * @phpstan-type Value float|bool|string|list<mixed>|RecordValue|null
 */
final readonly class Values
{
	public static function number(mixed $value, string $context): float
	{
		if (!is_float($value)) {
			throw self::wrong($value, 'a number', $context);
		}

		return $value;
	}

	public static function boolean(mixed $value, string $context): bool
	{
		if (!is_bool($value)) {
			throw self::wrong($value, 'true or false', $context);
		}

		return $value;
	}

	public static function string(mixed $value, string $context): string
	{
		if (!is_string($value)) {
			throw self::wrong($value, 'a string', $context);
		}

		return $value;
	}

	/** @return list<mixed> */
	public static function list(mixed $value, string $context): array
	{
		if (!is_array($value) || !array_is_list($value)) {
			throw self::wrong($value, 'a list', $context);
		}

		return $value;
	}

	public static function record(mixed $value, string $context): RecordValue
	{
		if (!$value instanceof RecordValue) {
			throw self::wrong($value, 'a record', $context);
		}

		return $value;
	}

	/** A number, boolean, string or null: what `==` and `contains` compare. */
	public static function isScalar(mixed $value): bool
	{
		return $value === null || is_float($value) || is_bool($value) || is_string($value);
	}

	/** `==`: null equals only null; otherwise two numbers, booleans or strings of one type. */
	public static function equals(mixed $a, mixed $b, string $context): bool
	{
		if ($a === null || $b === null) {
			return $a === $b;
		}
		if (!self::isScalar($a) || !self::isScalar($b) || get_debug_type($a) !== get_debug_type($b)) {
			throw new ExpressionException(
				ExpressionErrorEnum::Type,
				sprintf('%s compares %s with %s.', $context, self::typeName($a), self::typeName($b)),
			);
		}

		return $a === $b;
	}

	public static function typeName(mixed $value): string
	{
		return match (true) {
			$value === null => 'null',
			is_float($value) => 'a number',
			is_bool($value) => 'a boolean',
			is_string($value) => 'a string',
			$value instanceof RecordValue => 'a record',
			default => 'a list',
		};
	}

	public static function finite(float $value): float
	{
		if (!is_finite($value)) {
			throw new ExpressionException(ExpressionErrorEnum::NonFinite, 'Result is too large.');
		}

		return $value;
	}

	/**
	 * A decoded JSON value as an expression value: numbers become floats, lists lists, objects records. PHP decodes `{}`
	 * and objects with keys 0, 1, … as lists, so this is for values whose shape is known (fixtures, parameter values
	 * checked against their fields); a list parameter's records are built from its declared fields, not guessed here.
	 *
	 * @return Value
	 */
	public static function fromJson(mixed $value): mixed
	{
		if (is_int($value)) {
			return (float) $value;
		}
		if (!is_array($value)) {
			return is_float($value) || is_bool($value) || is_string($value) ? $value : null;
		}
		if (array_is_list($value)) {
			return array_map(self::fromJson(...), $value);
		}

		$fields = [];
		foreach ($value as $key => $field) {
			$fields[(string) $key] = self::fromJson($field);
		}

		return new RecordValue($fields);
	}

	/** The value as data for JSON: records as objects (so an empty one stays `{}`), lists as lists. */
	public static function toJson(mixed $value): mixed
	{
		if ($value instanceof RecordValue) {
			$object = new stdClass();
			foreach ($value->fields as $field => $item) {
				$object->{$field} = self::toJson($item);
			}

			return $object;
		}

		return is_array($value) ? array_map(self::toJson(...), $value) : $value;
	}

	private static function wrong(mixed $value, string $expected, string $context): ExpressionException
	{
		return new ExpressionException(
			$value === null ? ExpressionErrorEnum::Null : ExpressionErrorEnum::Type,
			sprintf('%s needs %s, got %s.', $context, $expected, self::typeName($value)),
		);
	}
}
