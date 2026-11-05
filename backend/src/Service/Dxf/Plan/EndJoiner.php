<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use function array_push;
use function hypot;
use function max;
use function usort;

/**
 * Joins the walls' ends: two free ends near each other meet where the centre lines cross (an L, which the editor
 * mitres), nearest first; then a free end near another wall's side runs onto its centre line (a T).
 */
final class EndJoiner
{
	/** How far past an end (beyond the walls' thickness) two walls still meet. */
	private const float Reach = 20.0;

	private const float Tolerance = 2.0;

	/** @param list<WallPiece> $walls */
	public static function join(array $walls): void
	{
		$corners = self::corners($walls);
		usort($corners, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
		foreach ($corners as [, $a, $aStart, $b, $bStart, [$x, $y]]) {
			if ($a->isJoined($aStart) || $b->isJoined($bStart) || !$a->canMove($aStart, $x, $y) || !$b->canMove($bStart, $x, $y)) {
				continue;
			}
			$a->moveEnd($aStart, $x, $y);
			$b->moveEnd($bStart, $x, $y);
			$a->markJoined($aStart);
			$b->markJoined($bStart);
		}

		foreach ($walls as $wall) {
			foreach ([true, false] as $start) {
				if (!$wall->isJoined($start)) {
					self::tee($wall, $start, $walls);
				}
			}
		}
	}

	/**
	 * @param list<WallPiece> $walls
	 * @return list<array{float, WallPiece, bool, WallPiece, bool, array{float, float}}> the ends' distance, both ends and where they meet
	 */
	private static function corners(array $walls): array
	{
		$corners = [];
		foreach ($walls as $i => $a) {
			foreach ($walls as $j => $b) {
				$cross = $j > $i ? $a->crossing($b) : null;
				if ($cross !== null) {
					array_push($corners, ...self::cornersOf($a, $b, $cross));
				}
			}
		}

		return $corners;
	}

	/**
	 * @param array{float, float} $cross
	 * @return list<array{float, WallPiece, bool, WallPiece, bool, array{float, float}}>
	 */
	private static function cornersOf(WallPiece $a, WallPiece $b, array $cross): array
	{
		$corners = [];
		foreach ([[true, true], [true, false], [false, true], [false, false]] as [$aStart, $bStart]) {
			$distance = self::cornerDistance($a, $aStart, $b, $bStart, $cross);
			if ($distance !== null) {
				$corners[] = [$distance, $a, $aStart, $b, $bStart, $cross];
			}
		}

		return $corners;
	}

	/**
	 * How far apart the two ends are, when they are near enough to meet at the crossing; null otherwise.
	 *
	 * @param array{float, float} $cross
	 */
	private static function cornerDistance(WallPiece $a, bool $aStart, WallPiece $b, bool $bStart, array $cross): ?float
	{
		[$ax, $ay] = $a->endPoint($aStart);
		[$bx, $by] = $b->endPoint($bStart);
		$distance = hypot($ax - $bx, $ay - $by);
		$near = $distance <= max($a->thickness, $b->thickness) + self::Reach
			&& hypot($cross[0] - $ax, $cross[1] - $ay) <= $b->thickness + self::Reach
			&& hypot($cross[0] - $bx, $cross[1] - $by) <= $a->thickness + self::Reach;

		return $near ? $distance : null;
	}

	/** @param list<WallPiece> $walls */
	private static function tee(WallPiece $wall, bool $start, array $walls): void
	{
		[$ex, $ey] = $wall->endPoint($start);
		$best = null;
		$bestDistance = null;
		foreach ($walls as $other) {
			$cross = $other === $wall ? null : $wall->crossing($other);
			if ($cross === null) {
				continue;
			}
			$along = $other->frame->t($cross[0], $cross[1]);
			$distance = hypot($cross[0] - $ex, $cross[1] - $ey);
			$onSide = $along >= $other->t0 - self::Tolerance && $along <= $other->t1 + self::Tolerance;
			if (
				!$onSide
				|| $distance > $other->thickness / 2 + self::Reach
				|| (
					$bestDistance !== null
					&& $distance >= $bestDistance
				)
				|| !$wall->canMove($start, $cross[0], $cross[1])
			) {
				continue;
			}

			$best = $cross;
			$bestDistance = $distance;
		}
		if ($best === null) {
			return;
		}

		$wall->moveEnd($start, $best[0], $best[1]);
		$wall->markJoined($start);
	}
}
