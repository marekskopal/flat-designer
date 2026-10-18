<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

/** Functions of the expression language; all take and return numbers. `round` rounds half away from zero. */
enum FunctionEnum: string
{
	case Min = 'min';
	case Max = 'max';
	case Round = 'round';
	case Floor = 'floor';
	case Ceil = 'ceil';
	case Abs = 'abs';
	case Clamp = 'clamp';

	/** @return array{int, int|null} the least and most arguments (null = any number) */
	public function arity(): array
	{
		return match ($this) {
			self::Min, self::Max => [1, null],
			self::Round, self::Floor, self::Ceil, self::Abs => [1, 1],
			self::Clamp => [3, 3],
		};
	}

	/** @param list<float> $rest the arguments after the first, as many as `arity()` allows */
	public function apply(float $first, array $rest): float
	{
		return match ($this) {
			self::Min => min([$first, ...$rest]),
			self::Max => max([$first, ...$rest]),
			self::Round => round($first),
			self::Floor => floor($first),
			self::Ceil => ceil($first),
			self::Abs => abs($first),
			self::Clamp => max($rest[0] ?? $first, min($rest[1] ?? $first, $first)),
		};
	}
}
