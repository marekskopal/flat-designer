<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use function abs;

/** A direction in the plan: `t` runs along `d`, `c` along its left normal `n`, so a point is t·d + c·n. */
final readonly class Frame
{
	public float $nx;

	public float $ny;

	public function __construct(public float $dx, public float $dy)
	{
		$this->nx = -$dy;
		$this->ny = $dx;
	}

	public function t(float $x, float $y): float
	{
		return $this->dx * $x + $this->dy * $y;
	}

	public function c(float $x, float $y): float
	{
		return $this->nx * $x + $this->ny * $y;
	}

	/** @return array{float, float} */
	public function point(float $t, float $c): array
	{
		return [$this->dx * $t + $this->nx * $c, $this->dy * $t + $this->ny * $c];
	}

	/** |cos| of the angle between the two directions. */
	public function alignment(self $other): float
	{
		return abs($this->dx * $other->dx + $this->dy * $other->dy);
	}
}
