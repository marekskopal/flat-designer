<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/** Stretches [from, to] along a line (mm), as `mergeSpans()` / `subtractSpans()` in the viewer's `ceilings.ts`. */
final class Spans
{
	/**
	 * @param list<array{float, float}> $spans
	 * @return list<array{float, float}>
	 */
	public static function merge(array $spans): array
	{
		$spans = array_values(array_filter($spans, static fn (array $span): bool => $span[1] > $span[0]));
		usort($spans, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
		$merged = [];
		foreach ($spans as [$from, $to]) {
			$last = array_key_last($merged);
			if ($last !== null && $from <= $merged[$last][1] + 1) {
				$merged[$last][1] = max($merged[$last][1], $to);
			} else {
				$merged[] = [$from, $to];
			}
		}

		return $merged;
	}

	/**
	 * Stretches of `spans` with the `holes` taken out; pieces of 1 mm or less are dropped.
	 *
	 * @param list<array{float, float}> $spans
	 * @param list<array{float, float}> $holes
	 * @return list<array{float, float}>
	 */
	public static function subtract(array $spans, array $holes): array
	{
		$pieces = $spans;
		foreach ($holes as [$holeFrom, $holeTo]) {
			$next = [];
			foreach ($pieces as [$from, $to]) {
				if ($holeTo <= $from || $holeFrom >= $to) {
					$next[] = [$from, $to];
					continue;
				}
				foreach ([[$from, $holeFrom], [$holeTo, $to]] as [$f, $t]) {
					if ($t - $f > 1) {
						$next[] = [$f, $t];
					}
				}
			}
			$pieces = $next;
		}

		return $pieces;
	}

	/**
	 * Ranges from 0 up to `top` that none of `bands` covers (the heights of a wall face no finish band covers).
	 *
	 * @param list<array{float, float}> $bands
	 * @return list<array{float, float}>
	 */
	public static function uncovered(float $top, array $bands): array
	{
		usort($bands, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
		$ranges = [];
		$from = 0.0;
		foreach ($bands as [$bottom, $bandTop]) {
			if ($bottom > $from) {
				$ranges[] = [$from, $bottom];
			}
			$from = max($from, $bandTop);
		}
		if ($top > $from) {
			$ranges[] = [$from, $top];
		}

		return $ranges;
	}
}
