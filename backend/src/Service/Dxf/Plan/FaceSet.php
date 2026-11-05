<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Service\Dxf\DxfSegment;
use function abs;
use function array_map;
use function atan2;
use function cos;
use function count;
use function deg2rad;
use function hypot;
use function max;
use function min;
use function round;
use function sin;
use function usort;
use const M_PI;

/**
 * The faces of the wall layers' lines (mm): lines of one direction (within half a degree) on one line (within a
 * millimetre) that overlap or touch merged into one face, each in the frame of its direction's longest line, so the
 * exact parallels of real drawings stay exact. A line may carry several faces, with gaps between them.
 */
final class FaceSet
{
	public const float Tolerance = 1.0;

	private const float BucketDegrees = 0.5;

	/** @var list<Face> */
	public array $faces = [];

	/** @var array<int, list<Face>> the faces by direction bucket */
	public array $byBucket = [];

	/** @var array<int, float> the total face length on each line */
	private array $lineLengths = [];

	private int $lines = 0;

	/** @param list<DxfSegment> $segments */
	public function __construct(array $segments)
	{
		foreach (self::buckets($segments) as $bucket => $entries) {
			$this->addBucket($bucket, $entries);
		}
	}

	/** How much more face the face's line carries besides it. */
	public function support(Face $face): float
	{
		return ($this->lineLengths[$face->line] ?? 0.0) - $face->length();
	}

	/** How much of the stretch of `t` the faces of the bucket on the line at `$c` cover. */
	public function covered(int $bucket, float $c, float $from, float $to): float
	{
		$covered = 0.0;
		foreach ($this->byBucket[$bucket] ?? [] as $face) {
			if (abs($face->c - $c) <= 2 * self::Tolerance) {
				$covered += max(0.0, min($to, $face->t1) - max($from, $face->t0));
			}
		}

		return $covered;
	}

	/**
	 * @param list<DxfSegment> $segments
	 * @return array<int, list<array{DxfSegment, float, float}>> each line with its length and direction (0 ≤ angle < π)
	 */
	private static function buckets(array $segments): array
	{
		$count = (int) round(180 / self::BucketDegrees);
		$buckets = [];
		foreach ($segments as $segment) {
			$length = hypot($segment->x2 - $segment->x1, $segment->y2 - $segment->y1);
			if ($length < self::Tolerance) {
				continue;
			}
			$angle = atan2($segment->y2 - $segment->y1, $segment->x2 - $segment->x1);
			$angle = $angle < 0 ? $angle + M_PI : $angle;
			$buckets[((int) round($angle / deg2rad(self::BucketDegrees))) % $count][] = [$segment, $length, $angle];
		}

		return $buckets;
	}

	/** @param list<array{DxfSegment, float, float}> $entries */
	private function addBucket(int $bucket, array $entries): void
	{
		usort($entries, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
		$frame = new Frame(cos($entries[0][2]), sin($entries[0][2]));
		$spans = array_map(static function (array $entry) use ($frame): array {
			$segment = $entry[0];
			$a = $frame->t($segment->x1, $segment->y1);
			$b = $frame->t($segment->x2, $segment->y2);

			return [($frame->c($segment->x1, $segment->y1) + $frame->c($segment->x2, $segment->y2)) / 2, min($a, $b), max($a, $b)];
		}, $entries);
		usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

		foreach (self::lines($spans) as $line) {
			$this->addLine($bucket, $frame, $line);
		}
	}

	/**
	 * Runs of spans whose offsets lie within the tolerance of the run's first.
	 *
	 * @param list<array{float, float, float}> $spans offset, from, to; sorted by offset
	 * @return list<list<array{float, float, float}>>
	 */
	private static function lines(array $spans): array
	{
		$lines = [];
		$last = -1;
		foreach ($spans as $span) {
			if ($last >= 0 && $span[0] - $lines[$last][0][0] <= self::Tolerance) {
				$lines[$last][] = $span;
				continue;
			}
			$lines[] = [$span];
			$last++;
		}

		return $lines;
	}

	/** @param list<array{float, float, float}> $spans */
	private function addLine(int $bucket, Frame $frame, array $spans): void
	{
		$c = 0.0;
		foreach ($spans as $span) {
			$c += $span[0];
		}
		$c /= count($spans);
		usort($spans, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

		$merged = [];
		$last = -1;
		foreach ($spans as [, $from, $to]) {
			if ($last >= 0 && $from <= $merged[$last][1] + self::Tolerance) {
				$merged[$last][1] = max($merged[$last][1], $to);
				continue;
			}
			$merged[] = [$from, $to];
			$last++;
		}

		$total = 0.0;
		foreach ($merged as [$from, $to]) {
			$face = new Face($bucket, $frame, $c, $from, $to, $this->lines);
			$this->faces[] = $face;
			$this->byBucket[$bucket][] = $face;
			$total += $to - $from;
		}
		$this->lineLengths[$this->lines] = $total;
		$this->lines++;
	}
}
