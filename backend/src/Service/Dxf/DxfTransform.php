<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

use function abs;
use function atan2;
use function cos;
use function deg2rad;
use function hypot;
use function sin;
use function sqrt;

/** A 2D affine map: x' = a·x + c·y + e, y' = b·x + d·y + f. Block inserts and object coordinate systems compose them. */
final readonly class DxfTransform
{
	public function __construct(
		public float $a = 1.0,
		public float $b = 0.0,
		public float $c = 0.0,
		public float $d = 1.0,
		public float $e = 0.0,
		public float $f = 0.0,
	) {
	}

	/** Scale, then turn (degrees counter-clockwise), then move: how an insert places its block's base point. */
	public static function placing(float $x, float $y, float $scaleX, float $scaleY, float $rotation): self
	{
		$cos = cos(deg2rad($rotation));
		$sin = sin(deg2rad($rotation));

		return new self($cos * $scaleX, $sin * $scaleX, -$sin * $scaleY, $cos * $scaleY, $x, $y);
	}

	/**
	 * The object coordinate system of an entity with this extrusion direction, projected onto the plan (the
	 * arbitrary axis algorithm of the DXF reference); null when the entity is not drawn flat on the plan.
	 */
	public static function ocs(float $nx, float $ny, float $nz): ?self
	{
		$length = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
		if ($length < 1e-12) {
			return new self();
		}
		[$nx, $ny, $nz] = [$nx / $length, $ny / $length, $nz / $length];
		if (abs($nz) < 0.999) {
			return null;
		}
		if (abs($nx) < 1 / 64 && abs($ny) < 1 / 64) {
			// Ax = Wy × N
			[$ax, $ay, $az] = [$nz, 0.0, -$nx];
		} else {
			// Ax = Wz × N
			[$ax, $ay, $az] = [-$ny, $nx, 0.0];
		}
		$axLength = sqrt($ax * $ax + $ay * $ay + $az * $az);
		[$ax, $ay, $az] = [$ax / $axLength, $ay / $axLength, $az / $axLength];
		// Ay = N × Ax
		$bx = $ny * $az - $nz * $ay;
		$by = $nz * $ax - $nx * $az;

		return new self($ax, $ay, $bx, $by);
	}

	/** This map after `$inner`: a point of the inner frame is mapped by `$inner` first. */
	public function after(self $inner): self
	{
		return new self(
			$this->a * $inner->a + $this->c * $inner->b,
			$this->b * $inner->a + $this->d * $inner->b,
			$this->a * $inner->c + $this->c * $inner->d,
			$this->b * $inner->c + $this->d * $inner->d,
			$this->a * $inner->e + $this->c * $inner->f + $this->e,
			$this->b * $inner->e + $this->d * $inner->f + $this->f,
		);
	}

	/** @return array{float, float} */
	public function apply(float $x, float $y): array
	{
		return [$this->a * $x + $this->c * $y + $this->e, $this->b * $x + $this->d * $y + $this->f];
	}

	public function determinant(): float
	{
		return $this->a * $this->d - $this->b * $this->c;
	}

	/** Whether circles stay circles: the axes stay square to each other and equally long. */
	public function isConformal(): bool
	{
		$x = hypot($this->a, $this->b);
		$y = hypot($this->c, $this->d);

		return abs($x - $y) <= 1e-9 * ($x + $y) && abs($this->a * $this->c + $this->b * $this->d) <= 1e-9 * ($x * $y + 1e-12);
	}

	public function scale(): float
	{
		return hypot($this->a, $this->b);
	}

	/** The angle (radians) a direction at `$angle` turns into. */
	public function angle(float $angle): float
	{
		return atan2($this->b * cos($angle) + $this->d * sin($angle), $this->a * cos($angle) + $this->c * sin($angle));
	}
}
