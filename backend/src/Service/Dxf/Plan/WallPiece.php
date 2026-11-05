<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use function abs;

/**
 * A straight wall found in the drawing (mm): its centre line `c` from `t0` to `t1` in its frame, its thickness, and
 * the openings in it as stretches of `t`.
 */
final class WallPiece
{
	/** @var list<PlannedOpening> */
	public array $openings = [];

	public bool $startJoined = false;

	public bool $endJoined = false;

	public function __construct(
		public readonly int $bucket,
		public readonly Frame $frame,
		public readonly float $c,
		public float $t0,
		public float $t1,
		public readonly float $thickness,
	) {
	}

	public function length(): float
	{
		return $this->t1 - $this->t0;
	}

	/** @return array{float, float} */
	public function start(): array
	{
		return $this->frame->point($this->t0, $this->c);
	}

	/** @return array{float, float} */
	public function end(): array
	{
		return $this->frame->point($this->t1, $this->c);
	}

	/** Whether the point lies in the wall's footprint, grown by `$margin`. */
	public function contains(float $x, float $y, float $margin = 0.0): bool
	{
		$t = $this->frame->t($x, $y);

		return abs($this->frame->c($x, $y) - $this->c) <= $this->thickness / 2 + $margin
			&& $t >= $this->t0 - $margin
			&& $t <= $this->t1 + $margin;
	}

	/**
	 * Where the centre lines of the two walls cross; null when they are about parallel.
	 *
	 * @return array{float, float}|null
	 */
	public function crossing(self $other): ?array
	{
		$a = $this->frame;
		$b = $other->frame;
		$det = $a->nx * $b->ny - $a->ny * $b->nx;
		if (abs($det) < 0.17) {
			// Within 10° of parallel.
			return null;
		}

		return [($this->c * $b->ny - $a->ny * $other->c) / $det, ($a->nx * $other->c - $this->c * $b->nx) / $det];
	}

	/** @return array{float, float} the start or the end */
	public function endPoint(bool $start): array
	{
		return $start ? $this->start() : $this->end();
	}

	public function isJoined(bool $start): bool
	{
		return $start ? $this->startJoined : $this->endJoined;
	}

	public function markJoined(bool $start): void
	{
		if ($start) {
			$this->startJoined = true;
		} else {
			$this->endJoined = true;
		}
	}

	/** Whether moving the end there keeps the wall pointing the same way. */
	public function canMove(bool $start, float $x, float $y): bool
	{
		$t = $this->frame->t($x, $y);

		return $start ? $t < $this->t1 - 2.0 : $t > $this->t0 + 2.0;
	}

	/** Moves the start (or the end) to the point of the centre line nearest the given one. */
	public function moveEnd(bool $start, float $x, float $y): void
	{
		$t = $this->frame->t($x, $y);
		if ($start) {
			$this->t0 = $t;
		} else {
			$this->t1 = $t;
		}
	}
}
