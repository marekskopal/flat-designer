<?php

declare(strict_types=1);

namespace FlatDesigner\Service\ModelAsset;

/** Column-major 4 × 4 matrices as glTF stores them, as flat lists of 16 floats. */
final readonly class Matrix4
{
	/** @return list<float> */
	public static function identity(): array
	{
		return [1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, 0.0, 1.0];
	}

	/**
	 * A node's own transform: its `matrix`, or translation · rotation · scale.
	 *
	 * @param array<mixed> $node
	 * @return list<float>
	 */
	public static function ofNode(array $node): array
	{
		$matrix = self::numbers($node['matrix'] ?? null, 16);
		if ($matrix !== null) {
			return $matrix;
		}
		$t = self::vector($node['translation'] ?? null) ?? [0.0, 0.0, 0.0];
		$s = self::vector($node['scale'] ?? null) ?? [1.0, 1.0, 1.0];
		[$x, $y, $z, $w] = self::numbers($node['rotation'] ?? null, 4) ?? [0.0, 0.0, 0.0, 1.0];

		// The columns are the rotated, scaled axes, then the translation.
		return [
			(1 - 2 * ($y * $y + $z * $z)) * $s[0],
			2 * ($x * $y + $z * $w) * $s[0],
			2 * ($x * $z - $y * $w) * $s[0],
			0.0,
			2 * ($x * $y - $z * $w) * $s[1],
			(1 - 2 * ($x * $x + $z * $z)) * $s[1],
			2 * ($y * $z + $x * $w) * $s[1],
			0.0,
			2 * ($x * $z + $y * $w) * $s[2],
			2 * ($y * $z - $x * $w) * $s[2],
			(1 - 2 * ($x * $x + $y * $y)) * $s[2],
			0.0,
			$t[0],
			$t[1],
			$t[2],
			1.0,
		];
	}

	/**
	 * @param list<float> $a
	 * @param list<float> $b
	 * @return list<float> a · b
	 */
	public static function multiply(array $a, array $b): array
	{
		$out = [];
		for ($column = 0; $column < 4; $column++) {
			for ($row = 0; $row < 4; $row++) {
				$sum = 0.0;
				for ($k = 0; $k < 4; $k++) {
					$sum += $a[$k * 4 + $row] * $b[$column * 4 + $k];
				}
				$out[] = $sum;
			}
		}

		return $out;
	}

	/**
	 * @param list<float> $m
	 * @param array{float, float, float} $point
	 * @return array{float, float, float}
	 */
	public static function apply(array $m, array $point): array
	{
		[$x, $y, $z] = $point;

		return [
			$m[0] * $x + $m[4] * $y + $m[8] * $z + $m[12],
			$m[1] * $x + $m[5] * $y + $m[9] * $z + $m[13],
			$m[2] * $x + $m[6] * $y + $m[10] * $z + $m[14],
		];
	}

	/** @return array{float, float, float}|null */
	public static function vector(mixed $value): ?array
	{
		$numbers = is_array($value) && count($value) >= 3 ? self::numbers(array_slice(array_values($value), 0, 3), 3) : null;

		return $numbers === null ? null : [$numbers[0], $numbers[1], $numbers[2]];
	}

	/** @return list<float>|null exactly `$count` numbers */
	private static function numbers(mixed $value, int $count): ?array
	{
		if (!is_array($value) || count($value) !== $count) {
			return null;
		}
		$numbers = [];
		foreach ($value as $number) {
			if (!is_int($number) && !is_float($number)) {
				return null;
			}
			$numbers[] = (float) $number;
		}

		return $numbers;
	}
}
