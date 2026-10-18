<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

use FlatDesigner\Service\Furniture\Expression\Node\Values;

/**
 * Functions of the expression language. The number functions take and return numbers (`round` rounds half away from
 * zero); the list functions are in `ListFunctions`.
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

	/** @return array{int, int|null} the least and most arguments (null = any number) */
	public function arity(): array
	{
		return match ($this) {
			self::Min, self::Max => [1, null],
			self::Round, self::Floor, self::Ceil, self::Abs, self::Len, self::Sum => [1, 1],
			self::Clamp => [3, 3],
			self::Contains, self::Field => [2, 2],
			self::Share => [2, 3],
			self::Offsets => [1, 2],
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
		};
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
