<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use function cos;
use function sin;

/**
 * A circular arc in world coordinates, running counter-clockwise from `start` to `end` (radians, `start` < `end`
 * ≤ `start` + 2π; a circle is 0 to 2π).
 */
final readonly class DxfArc
{
	public function __construct(
		public string $layer,
		public float $cx,
		public float $cy,
		public float $radius,
		public float $start,
		public float $end,
		public ?string $block = null,
	) {
	}

	public function scaled(float $factor): self
	{
		return new self(
			$this->layer,
			$this->cx * $factor,
			$this->cy * $factor,
			$this->radius * $factor,
			$this->start,
			$this->end,
			$this->block,
		);
	}

	/** @return array{float, float} the point at the given angle (radians) */
	public function pointAt(float $angle): array
	{
		return [$this->cx + $this->radius * cos($angle), $this->cy + $this->radius * sin($angle)];
	}

	/**
	 * Points along the arc, both ends included.
	 *
	 * @return list<array{float, float}>
	 */
	public function samples(int $count): array
	{
		$points = [];
		for ($i = 0; $i <= $count; $i++) {
			$points[] = $this->pointAt($this->start + ($this->end - $this->start) * $i / $count);
		}

		return $points;
	}
}
