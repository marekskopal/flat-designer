<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

/** A curved wall found in the drawing (mm): its centre line runs counter-clockwise round (cx, cy) from `start` to `end` (radians, at most π). */
final readonly class CurvedPiece
{
	public function __construct(
		public float $cx,
		public float $cy,
		public float $radius,
		public float $start,
		public float $end,
		public float $thickness,
	) {
	}
}
