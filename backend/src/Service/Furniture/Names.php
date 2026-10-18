<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

/** Names of variables in a definition: parameters, derived values, repeat indexes and material slots. */
final readonly class Names
{
	/**
	 * The item size, an assembly's size (AW, AH, AD), the literals true, false and null, and `__proto__` (a plain JS object cannot hold it); no parameter, derived
	 * value or repeat index may take them.
	 */
	private const array Reserved = ['W', 'D', 'H', 'AW', 'AH', 'AD', 'true', 'false', 'null', '__proto__'];

	/** The message for a name that is not free. */
	public const string Rule = 'Must be a unique name (letters, digits, _), not W, D, H, AW, AH, AD, true, false or null.';

	/** @phpstan-assert-if-true string $value */
	public static function isName(mixed $value): bool
	{
		return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
	}

	/**
	 * `$value` when it is a name for a new variable (not reserved, not one of `$taken`), otherwise null.
	 *
	 * @param array<string, mixed> $taken
	 */
	public static function free(mixed $value, array $taken): ?string
	{
		return self::isName($value) && !in_array($value, self::Reserved, true) && !array_key_exists($value, $taken) ? $value : null;
	}
}
