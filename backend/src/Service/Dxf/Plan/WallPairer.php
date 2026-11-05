<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use function abs;
use function count;
use function max;
use function min;
use function usort;

/**
 * Pairs faces into straight wall pieces: two parallel faces a wall's thickness apart make a piece over the stretch
 * they overlap, each stretch of a face pairing once. The best pairs go first: the overlap plus how much more face both
 * lines carry, so a stub between two longer pieces of one wall runs along them, not across. A squat pair whose faces
 * are both ends of walls found already (the jambs of a doorway) does not pair, and a piece whose middle lies in a wall
 * found already is left out.
 *
 * @phpstan-type Candidate array{a: Face, b: Face, from: float, to: float, squat: bool, score: float}
 */
final class WallPairer
{
	public const float MinThickness = 50.0;

	public const float MaxThickness = 700.0;

	/** Shortest stretch two faces must overlap to make a wall piece. */
	private const float MinOverlap = 20.0;

	/** How far a jamb may be off a wall's end and sides. */
	private const float JambTolerance = 3.0;

	/** @var list<WallPiece> */
	private array $pieces = [];

	/** @return list<WallPiece> */
	public function pair(FaceSet $faces): array
	{
		$this->pieces = [];
		$candidates = self::candidates($faces);
		usort($candidates, static fn (array $x, array $y): int => $y['score'] <=> $x['score']);
		foreach ($candidates as $candidate) {
			$this->take($candidate);
		}

		return $this->pieces;
	}

	/** @return list<Candidate> */
	private static function candidates(FaceSet $set): array
	{
		$candidates = [];
		foreach ($set->byBucket as $faces) {
			usort($faces, static fn (Face $a, Face $b): int => $a->c <=> $b->c);
			$count = count($faces);
			for ($i = 0; $i < $count; $i++) {
				for ($j = $i + 1; $j < $count && $faces[$j]->c - $faces[$i]->c <= self::MaxThickness; $j++) {
					$candidate = self::candidate($set, $faces[$i], $faces[$j]);
					if ($candidate !== null) {
						$candidates[] = $candidate;
					}
				}
			}
		}

		return $candidates;
	}

	/** @return Candidate|null */
	private static function candidate(FaceSet $set, Face $a, Face $b): ?array
	{
		$thickness = $b->c - $a->c;
		$from = max($a->t0, $b->t0);
		$to = min($a->t1, $b->t1);
		if ($thickness < self::MinThickness || $to - $from < self::MinOverlap) {
			return null;
		}

		return [
			'a' => $a,
			'b' => $b,
			'from' => $from,
			'to' => $to,
			'squat' => $to - $from < $thickness,
			'score' => $to - $from + min($set->support($a), $set->support($b)),
		];
	}

	/** @param Candidate $candidate */
	private function take(array $candidate): void
	{
		$a = $candidate['a'];
		$b = $candidate['b'];
		$c = ($a->c + $b->c) / 2;
		foreach (Intervals::subtract(Intervals::subtract([[$candidate['from'], $candidate['to']]], $a->used), $b->used) as [$from, $to]) {
			if ($to - $from < self::MinOverlap) {
				continue;
			}
			[$x, $y] = $a->frame->point(($from + $to) / 2, $c);
			if ($this->inPiece($x, $y) || ($candidate['squat'] && $this->isJamb($a, $from, $to) && $this->isJamb($b, $from, $to))) {
				continue;
			}
			$this->pieces[] = new WallPiece($a->bucket, $a->frame, $c, $from, $to, $b->c - $a->c);
			$a->used[] = [$from, $to];
			$b->used[] = [$from, $to];
		}
	}

	private function inPiece(float $x, float $y): bool
	{
		foreach ($this->pieces as $piece) {
			if ($piece->contains($x, $y, -FaceSet::Tolerance)) {
				return true;
			}
		}

		return false;
	}

	/** Whether the stretch of the face is the end of a wall piece found already: square to it, across its thickness. */
	private function isJamb(Face $face, float $from, float $to): bool
	{
		[$ax, $ay] = $face->frame->point($from, $face->c);
		[$bx, $by] = $face->frame->point($to, $face->c);
		foreach ($this->pieces as $piece) {
			if ($piece->frame->alignment($face->frame) > 0.05) {
				continue;
			}
			$along = $piece->frame->t(($ax + $bx) / 2, ($ay + $by) / 2);
			$atEnd = abs($along - $piece->t0) <= self::JambTolerance || abs($along - $piece->t1) <= self::JambTolerance;
			$half = $piece->thickness / 2 + self::JambTolerance;
			if ($atEnd && abs($piece->frame->c($ax, $ay) - $piece->c) <= $half && abs($piece->frame->c($bx, $by) - $piece->c) <= $half) {
				return true;
			}
		}

		return false;
	}
}
