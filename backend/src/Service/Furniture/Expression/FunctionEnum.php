<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture\Expression;

use Izbik\Service\Furniture\Expression\Node\Values;
use const M_PI;

/**
 * Functions of the expression language. The number functions take and return numbers (`round` rounds half away from
 * zero; `sin`, `cos`, `tan` take degrees and `atan2(y, x)` gives them, e.g. a slanted panel's angle, rounded to 1e-12 so
 * that the twins agree although their maths libraries differ in the last bits, and `cos(90)` is 0); the list functions
 * are in `ListFunctions`.
 *
 * @phpstan-import-type Value from Values
 */
enum FunctionEnum: string
{
	case Min = 'min';
	case Max = 'max';
	case Round = 'round';
	case Floor = 'floor';
	case Ceil = 'ceil';
	case Abs = 'abs';
	case Clamp = 'clamp';
	case Len = 'len';
	case Sum = 'sum';
	case Contains = 'contains';
	case Field = 'field';
	case Share = 'share';
	case Offsets = 'offsets';
	case Hash = 'hash';
	case Sqrt = 'sqrt';
	case Hypot = 'hypot';
	case Sin = 'sin';
	case Cos = 'cos';
	case Tan = 'tan';
	case Atan2 = 'atan2';

	/** @return array{int, int|null} the least and most arguments (null = any number) */
	public function arity(): array
	{
		return match ($this) {
			self::Min, self::Max => [1, null],
			self::Round, self::Floor, self::Ceil, self::Abs, self::Len, self::Sum, self::Sqrt, self::Sin, self::Cos, self::Tan => [1, 1],
			self::Clamp => [3, 3],
			self::Contains, self::Field, self::Hypot, self::Atan2 => [2, 2],
			self::Share => [2, 3],
			self::Offsets, self::Hash => [1, 2],
		};
	}

	/** The function called with `$count` arguments, or the arity error. */
	public function checkArity(int $count): void
	{
		[$least, $most] = $this->arity();
		if ($count < $least || $most !== null && $count > $most) {
			throw new ExpressionException(ExpressionErrorEnum::Arity, sprintf(
				'Function "%s" takes %s, got %d.',
				$this->value,
				match (true) {
					$most === null => sprintf('at least %d argument(s)', $least),
					$most === $least => sprintf('%d argument(s)', $least),
					default => sprintf('%d to %d argument(s)', $least, $most),
				},
				$count,
			));
		}
	}

	/**
	 * @param list<Value> $arguments as many as `arity()` allows
	 * @return float|bool|list<Value>
	 */
	public function apply(array $arguments): float|bool|array
	{
		$context = sprintf('Function "%s"', $this->value);
		$number = static fn (int $index): float => Values::number($arguments[$index] ?? null, $context);
		$list = static fn (int $index): array => Values::list($arguments[$index] ?? null, $context);

		return match ($this) {
			self::Min => min([$number(0), ...self::numbers(array_slice($arguments, 1), $context)]),
			self::Max => max([$number(0), ...self::numbers(array_slice($arguments, 1), $context)]),
			self::Round => round($number(0)),
			self::Floor => floor($number(0)),
			self::Ceil => ceil($number(0)),
			self::Abs => abs($number(0)),
			self::Clamp => max($number(1), min($number(2), $number(0))),
			self::Len => (float) count($list(0)),
			self::Sum => ListFunctions::sum($list(0)),
			self::Contains => ListFunctions::contains($list(0), $arguments[1] ?? null),
			self::Field => ListFunctions::field($list(0), Values::string($arguments[1] ?? null, $context)),
			self::Share => ListFunctions::share($list(0), $number(1), array_key_exists(2, $arguments) ? $number(2) : 0.0),
			self::Offsets => ListFunctions::offsets($list(0), array_key_exists(1, $arguments) ? $number(1) : 0.0),
			self::Hash => self::hash($number(0), array_key_exists(1, $arguments) ? $number(1) : 0.0),
			self::Sqrt => self::sqrt($number(0)),
			self::Hypot => Values::finite(hypot($number(0), $number(1))),
			self::Sin => self::snap(sin(self::radians($number(0)))),
			self::Cos => self::snap(cos(self::radians($number(0)))),
			self::Tan => self::tan($number(0)),
			self::Atan2 => self::snap(atan2($number(0), $number(1)) / M_PI * 180),
		};
	}

	/**
	 * A fixed pseudo-random number from 0 up to (not including) 1 for whole numbers `n` and `seed` (rounded half away
	 * from zero, taken modulo 2^32), e.g. book spines varying by their repeat index. 32-bit integer mixing, so that the
	 * TS twin gets the very same numbers.
	 */
	public static function hash(float $n, float $seed): float
	{
		$x = self::uint32($n) ^ self::multiply(self::uint32($seed, 1), 0x9E3779B9);
		$x = self::multiply($x ^ ($x >> 16), 0x7FEB352D);
		$x = self::multiply($x ^ ($x >> 15), 0x846CA68B);

		return ($x ^ ($x >> 16)) / 4294967296;
	}

	/** Degrees to radians, in the twin's order of operations. */
	private static function radians(float $degrees): float
	{
		return Values::finite($degrees / 180 * M_PI);
	}

	/** Rounded to a multiple of 1e-12 (half away from zero, as the twin). */
	private static function snap(float $value): float
	{
		return Values::finite(round($value * 1e12) / 1e12);
	}

	/** The rounded sine over the rounded cosine, so 90° (cosine 0) is refused. */
	private static function tan(float $degrees): float
	{
		$cos = self::snap(cos(self::radians($degrees)));
		if ($cos === 0.0) {
			throw new ExpressionException(ExpressionErrorEnum::DivisionByZero, 'Function "tan" of an odd multiple of 90°.');
		}

		return self::snap(self::snap(sin(self::radians($degrees))) / $cos);
	}

	private static function sqrt(float $value): float
	{
		if ($value < 0) {
			throw new ExpressionException(ExpressionErrorEnum::Type, 'Function "sqrt" needs a number of 0 or more.');
		}

		return sqrt($value);
	}

	/** The whole number (plus `$add`) modulo 2^32. */
	private static function uint32(float $value, int $add = 0): int
	{
		$whole = round($value) + $add;
		if (!is_finite($whole) || abs($whole) > 2 ** 53) {
			throw new ExpressionException(ExpressionErrorEnum::Type, 'Function "hash" needs whole numbers up to 2^53.');
		}

		return (int) fmod(fmod($whole, 4294967296.0) + 4294967296.0, 4294967296.0);
	}

	/** The low 32 bits of a 32 × 32-bit product, without overflowing PHP's 64-bit integers. */
	private static function multiply(int $a, int $b): int
	{
		$low = ($a & 0xFFFF) * $b;
		$high = ($a >> 16) * $b & 0xFFFF;

		return $low + ($high << 16) & 0xFFFFFFFF;
	}

	/**
	 * @param list<Value> $values
	 * @return list<float>
	 */
	private static function numbers(array $values, string $context): array
	{
		return array_map(static fn (mixed $value): float => Values::number($value, $context), $values);
	}
}
