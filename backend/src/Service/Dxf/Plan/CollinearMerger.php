<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use function abs;
use function max;
use function usort;

/**
 * Joins collinear wall pieces of one thickness into walls. The gap between two pieces, up to the widest opening, is
 * part of the wall unless another wall's piece lies along it; it is an opening where both faces stop there and no wall
 * crosses it (where one face runs on, a wall meets this one from the other side).
 */
final class CollinearMerger
{
	/** Widest gap between two pieces of one wall taken as an opening. */
	public const float MaxOpening = 3500.0;

	private const float Tolerance = 2.0;

	/** @var list<WallPiece> */
	public array $walls = [];

	/** @var list<array{WallPiece, float, float}> the openings between pieces: the wall and the stretch of `t` */
	public array $gaps = [];

	/** @param list<WallPiece> $pieces */
	public function __construct(private readonly FaceSet $faces, private readonly array $pieces)
	{
		foreach (self::groups($pieces) as $group) {
			$this->mergeGroup($group);
		}
	}

	/**
	 * Pieces on one centre line with one thickness, along it.
	 *
	 * @param list<WallPiece> $pieces
	 * @return list<list<WallPiece>>
	 */
	private static function groups(array $pieces): array
	{
		$groups = [];
		foreach ($pieces as $piece) {
			$key = self::groupOf($groups, $piece);
			if ($key === null) {
				$groups[] = [$piece];
			} else {
				$groups[$key][] = $piece;
			}
		}
		$sorted = [];
		foreach ($groups as $group) {
			usort($group, static fn (WallPiece $a, WallPiece $b): int => $a->t0 <=> $b->t0);
			$sorted[] = $group;
		}

		return $sorted;
	}

	/** @param array<int, list<WallPiece>> $groups */
	private static function groupOf(array $groups, WallPiece $piece): ?int
	{
		foreach ($groups as $key => $group) {
			$first = $group[0];
			if ($first->bucket === $piece->bucket
				&& abs($first->c - $piece->c) <= self::Tolerance
				&& abs($first->thickness - $piece->thickness) <= self::Tolerance) {
				return $key;
			}
		}

		return null;
	}

	/** @param list<WallPiece> $group */
	private function mergeGroup(array $group): void
	{
		$current = null;
		foreach ($group as $piece) {
			if ($current !== null && $this->joins($current, $piece)) {
				if ($piece->t0 - $current->t1 > self::Tolerance && $this->isOpening($current, $current->t1, $piece->t0)) {
					$this->gaps[] = [$current, $current->t1, $piece->t0];
				}
				$current->t1 = max($current->t1, $piece->t1);
				continue;
			}
			$current = new WallPiece($piece->bucket, $piece->frame, $piece->c, $piece->t0, $piece->t1, $piece->thickness);
			$this->walls[] = $current;
		}
	}

	private function joins(WallPiece $before, WallPiece $after): bool
	{
		$gap = $after->t0 - $before->t1;
		if ($gap <= self::Tolerance) {
			return true;
		}
		if ($gap > self::MaxOpening) {
			return false;
		}
		// A parallel piece of another thickness lying along the gap: the line changes there.
		[$x, $y] = $before->frame->point(($before->t1 + $after->t0) / 2, $before->c);
		foreach ($this->pieces as $piece) {
			if ($piece->frame->alignment($before->frame) >= 0.99 && $piece->contains($x, $y, self::Tolerance)) {
				return false;
			}
		}

		return true;
	}

	/** Whether the gap is an opening: both faces stop there and no wall crosses it. */
	private function isOpening(WallPiece $wall, float $from, float $to): bool
	{
		[$x, $y] = $wall->frame->point(($from + $to) / 2, $wall->c);
		foreach ($this->pieces as $piece) {
			if ($piece->frame->alignment($wall->frame) < 0.99 && $piece->contains($x, $y, self::Tolerance)) {
				return false;
			}
		}
		foreach ([-1, 1] as $side) {
			if ($this->faces->covered($wall->bucket, $wall->c + $side * $wall->thickness / 2, $from, $to) >= 0.9 * ($to - $from)) {
				return false;
			}
		}

		return true;
	}
}
