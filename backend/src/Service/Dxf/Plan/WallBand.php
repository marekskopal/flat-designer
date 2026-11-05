<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Service\Dxf\DxfArc;
use Izbik\Service\Dxf\DxfSegment;
use function abs;
use function max;
use function min;

/** What of a drawing lies on a wall's footprint (grown by a margin), along the wall's `t`. */
final readonly class WallBand
{
	private float $half;

	public function __construct(private WallPiece $wall, float $margin)
	{
		$this->half = $wall->thickness / 2 + $margin;
	}

	/** @return array{float, float}|null the stretch of `t` of the line lying on the band */
	public function clip(DxfSegment $segment): ?array
	{
		$frame = $this->wall->frame;
		$u1 = $frame->c($segment->x1, $segment->y1) - $this->wall->c;
		$u2 = $frame->c($segment->x2, $segment->y2) - $this->wall->c;
		[$s0, $s1] = abs($u2 - $u1) < 1e-9
			? (abs($u1) <= $this->half ? [0.0, 1.0] : [1.0, 0.0])
			: self::crossing($u1, $u2, $this->half);
		if ($s0 > $s1) {
			return null;
		}
		$t1 = $frame->t($segment->x1, $segment->y1);
		$t2 = $frame->t($segment->x2, $segment->y2);
		$a = $t1 + ($t2 - $t1) * $s0;
		$b = $t1 + ($t2 - $t1) * $s1;

		return [min($a, $b), max($a, $b)];
	}

	/**
	 * The stretch of the wall the entities draw on the band: lines clipped to it, arcs by their points on it; within
	 * the wall's ends.
	 *
	 * @param list<DxfSegment|DxfArc> $entities
	 * @return array{float, float}|null
	 */
	public function extent(array $entities): ?array
	{
		$from = null;
		$to = null;
		foreach ($entities as $entity) {
			foreach ($this->stretches($entity) as [$a, $b]) {
				$a = max($a, $this->wall->t0);
				$b = min($b, $this->wall->t1);
				if ($a > $b) {
					continue;
				}

				$from = $from === null ? $a : min($from, $a);
				$to = $to === null ? $b : max($to, $b);
			}
		}

		return $from === null || $to === null ? null : [$from, $to];
	}

	public function containsPoint(float $x, float $y): bool
	{
		return abs($this->wall->frame->c($x, $y) - $this->wall->c) <= $this->half;
	}

	/** @return list<array{float, float}> */
	private function stretches(DxfSegment|DxfArc $entity): array
	{
		if ($entity instanceof DxfSegment) {
			$clipped = $this->clip($entity);

			return $clipped === null ? [] : [$clipped];
		}
		$stretches = [];
		foreach ($entity->samples(32) as [$x, $y]) {
			if ($this->containsPoint($x, $y)) {
				$t = $this->wall->frame->t($x, $y);
				$stretches[] = [$t, $t];
			}
		}

		return $stretches;
	}

	/** @return array{float, float} the parameters between which a line from offset u1 to u2 lies within ±half */
	private static function crossing(float $u1, float $u2, float $half): array
	{
		$a = (-$half - $u1) / ($u2 - $u1);
		$b = ($half - $u1) / ($u2 - $u1);

		return [max(0.0, min($a, $b)), min(1.0, max($a, $b))];
	}
}
