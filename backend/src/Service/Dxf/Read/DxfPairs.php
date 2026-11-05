<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Read;

use function count;
use function is_numeric;

/**
 * Looking up values in a record's group pairs.
 *
 * @phpstan-import-type Pair from DxfTokenizer
 */
final class DxfPairs
{
	/** @param list<Pair> $pairs */
	public static function value(array $pairs, int $code): ?string
	{
		foreach ($pairs as [$pairCode, $value]) {
			if ($pairCode === $code) {
				return $value;
			}
		}

		return null;
	}

	/** @param list<Pair> $pairs */
	public static function number(array $pairs, int $code, float $default = 0.0): float
	{
		$value = self::value($pairs, $code);

		return $value !== null && is_numeric($value) ? (float) $value : $default;
	}

	/** @param list<Pair> $pairs */
	public static function int(array $pairs, int $code, int $default = 0): int
	{
		$value = self::value($pairs, $code);

		return $value !== null && is_numeric($value) ? (int) $value : $default;
	}

	/** @param list<Pair> $pairs */
	public static function indexOf(array $pairs, int $code, int $from): ?int
	{
		$count = count($pairs);
		for ($i = $from; $i < $count; $i++) {
			if ($pairs[$i][0] === $code) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * The value of the next pair with the code after `$at`, moving `$at` to it; null when there is none.
	 *
	 * @param list<Pair> $pairs
	 */
	public static function scan(array $pairs, int $code, int &$at): ?float
	{
		$index = self::indexOf($pairs, $code, $at + 1);
		if ($index === null) {
			return null;
		}
		$at = $index;

		return (float) $pairs[$index][1];
	}

	/**
	 * The values of the next pairs with these codes, in order; null when one is missing.
	 *
	 * @param list<Pair> $pairs
	 * @param list<int> $codes
	 * @return list<float>|null
	 */
	public static function scanAll(array $pairs, array $codes, int &$at): ?array
	{
		$values = [];
		foreach ($codes as $code) {
			$value = self::scan($pairs, $code, $at);
			if ($value === null) {
				return null;
			}
			$values[] = $value;
		}

		return $values;
	}
}
