<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

/**
 * A straight face of a wall in the drawing (mm): collinear lines merged, in the frame of its direction bucket
 * (`t` along `d`, `c` the offset along the left normal `n`). `used` are the stretches wall pieces have taken.
 */
final class Face
{
	/** @var list<array{float, float}> */
	public array $used = [];

	public function __construct(
		public readonly int $bucket,
		public readonly Frame $frame,
		public readonly float $c,
		public readonly float $t0,
		public readonly float $t1,
		public readonly int $line,
	) {
	}

	public function length(): float
	{
		return $this->t1 - $this->t0;
	}

	public function usedLength(): float
	{
		$length = 0.0;
		foreach ($this->used as [$from, $to]) {
			$length += $to - $from;
		}

		return $length;
	}
}
