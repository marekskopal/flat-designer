<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

/** Stretches along a line, as [from, to] pairs. */
final class Intervals
{
	/**
	 * The stretches of `$intervals` outside every one of `$cut`.
	 *
	 * @param list<array{float, float}> $intervals
	 * @param list<array{float, float}> $cut
	 * @return list<array{float, float}>
	 */
	public static function subtract(array $intervals, array $cut): array
	{
		foreach ($cut as $stretch) {
			$next = [];
			foreach ($intervals as $interval) {
				foreach (self::outside($interval, $stretch) as $rest) {
					$next[] = $rest;
				}
			}
			$intervals = $next;
		}

		return $intervals;
	}

	/**
	 * @param array{float, float} $interval
	 * @param array{float, float} $cut
	 * @return list<array{float, float}>
	 */
	private static function outside(array $interval, array $cut): array
	{
		[$from, $to] = $interval;
		[$cutFrom, $cutTo] = $cut;
		if ($cutTo <= $from || $cutFrom >= $to) {
			return [$interval];
		}
		$rest = [];
		if ($cutFrom > $from) {
			$rest[] = [$from, $cutFrom];
		}
		if ($cutTo < $to) {
			$rest[] = [$cutTo, $to];
		}

		return $rest;
	}
}
