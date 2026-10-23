<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Wall;
use const M_PI;

/**
 * The part of a wall that defines its footprint: the centre line and the thickness (mm). The centre line is straight,
 * or for a `sagitta` (mm from the chord's midpoint to the arc, + to the left of start → end) an arc of at most a
 * semicircle. Twin of `wallFrame()` and its helpers in `viewer/engine/plan-geometry.ts`.
 *
 * @phpstan-import-type Point from PlanMath
 * @phpstan-type Arc array{cx: float, cy: float, radius: float, startAngle: float, sweep: float}
 */
final readonly class WallLine
{
	/** How far (mm) a straight piece standing in for an arc may be off it, about (the TS `ArcSag`). */
	private const int ArcSag = 3;

	private const int MaxArcSegments = 64;

	/** @var Arc|null the circle of a curved wall's centre line, computed once */
	private ?array $arc;

	public function __construct(
		public int $id,
		public int $startX,
		public int $startY,
		public int $endX,
		public int $endY,
		public int $thickness,
		public int $sagitta = 0,
	) {
		$this->arc = $this->computeArc();
	}

	public static function fromWall(Wall $wall): self
	{
		return new self($wall->id, $wall->startX, $wall->startY, $wall->endX, $wall->endY, $wall->thickness, $wall->sagitta);
	}

	public static function fromDto(WallDto $wall): self
	{
		return new self($wall->id, $wall->startX, $wall->startY, $wall->endX, $wall->endY, $wall->thickness, $wall->sagitta);
	}

	public function chord(): float
	{
		return hypot($this->endX - $this->startX, $this->endY - $this->startY);
	}

	/** Length of the centre line, along the arc for a curved wall. */
	public function length(): float
	{
		$arc = $this->arc();

		return $arc === null ? $this->chord() : abs($arc['sweep']) * $arc['radius'];
	}

	/**
	 * The circle of a curved wall's centre line: it turns through `sweep` (radians, counter-clockwise positive) from
	 * `startAngle` (of the start point about the centre); null for a straight wall.
	 *
	 * @return Arc|null
	 */
	public function arc(): ?array
	{
		return $this->arc;
	}

	/** @return Arc|null */
	private function computeArc(): ?array
	{
		$chord = $this->chord();
		if ($this->sagitta === 0 || $chord <= 0.0) {
			return null;
		}
		$sign = $this->sagitta > 0 ? 1 : -1;
		$radius = ($chord * $chord / 4 + $this->sagitta * $this->sagitta) / (2 * abs($this->sagitta));
		// The centre lies on the chord's bisector, on the side away from the bulge.
		$offset = $this->sagitta - $sign * $radius;
		$nx = -($this->endY - $this->startY) / $chord;
		$ny = ($this->endX - $this->startX) / $chord;
		$cx = ($this->startX + $this->endX) / 2 + $nx * $offset;
		$cy = ($this->startY + $this->endY) / 2 + $ny * $offset;
		$half = asin(min($chord / (2 * $radius), 1.0));

		return [
			'cx' => $cx,
			'cy' => $cy,
			'radius' => $radius,
			'startAngle' => atan2($this->startY - $cy, $this->startX - $cx),
			// Bulging to the left, the wall turns clockwise.
			'sweep' => -$sign * 2 * $half,
		];
	}

	/** How many straight pieces stand in for the arc in footprints: each off it by about `ArcSag` at most; 1 when straight. */
	public function segments(): int
	{
		if ($this->arc() === null) {
			return 1;
		}

		return min(self::MaxArcSegments, max(2, (int) ceil(sqrt(abs($this->sagitta) / self::ArcSag))));
	}

	/**
	 * Point at `along` mm on the centre line from the start and `across` mm to its left. On a curved wall `across` is
	 * radial, and an `along` beyond the ends runs on along the circle.
	 *
	 * @return Point
	 */
	public function point(float $along, float $across): array
	{
		$arc = $this->arc();
		if ($arc === null) {
			$length = $this->chord();
			$dx = ($this->endX - $this->startX) / $length;
			$dy = ($this->endY - $this->startY) / $length;

			return [$this->startX + $dx * $along - $dy * $across, $this->startY + $dy * $along + $dx * $across];
		}
		$angle = $arc['startAngle'] + $arc['sweep'] * $along / $this->length();
		// The left of a clockwise arc is outwards.
		$distance = $arc['radius'] - ($arc['sweep'] > 0 ? 1 : -1) * $across;

		return [$arc['cx'] + cos($angle) * $distance, $arc['cy'] + sin($angle) * $distance];
	}

	/**
	 * Unit vector along the centre line (towards the end) at `along`.
	 *
	 * @return Point
	 */
	public function tangent(float $along): array
	{
		$arc = $this->arc();
		if ($arc === null) {
			$length = $this->chord();

			return [($this->endX - $this->startX) / $length, ($this->endY - $this->startY) / $length];
		}
		$angle = $arc['startAngle'] + $arc['sweep'] * $along / $this->length();
		$sign = $arc['sweep'] > 0 ? 1 : -1;

		return [-sin($angle) * $sign, cos($angle) * $sign];
	}

	/**
	 * Distance along the centre line of a point's projection onto it, mm from the start; on a curved wall by its angle
	 * about the centre (within half a turn of the arc's middle).
	 *
	 * @param Point $point
	 */
	public function along(array $point): float
	{
		$arc = $this->arc();
		if ($arc === null) {
			$length = $this->chord();

			return (($point[0] - $this->startX) * ($this->endX - $this->startX) + ($point[1] - $this->startY) * ($this->endY - $this->startY)) / $length;
		}
		$middle = $arc['startAngle'] + $arc['sweep'] / 2;
		$delta = atan2($point[1] - $arc['cy'], $point[0] - $arc['cx']) - $middle;
		$delta -= 2 * M_PI * round($delta / (2 * M_PI));

		return $this->length() / 2 + $delta / $arc['sweep'] * $this->length();
	}

	/**
	 * Distance of a point from the centre line, mm, positive on its left (radial on a curved wall).
	 *
	 * @param Point $point
	 */
	public function across(array $point): float
	{
		$arc = $this->arc();
		if ($arc === null) {
			$length = $this->chord();

			return (($point[0] - $this->startX) * -($this->endY - $this->startY) + ($point[1] - $this->startY) * ($this->endX - $this->startX)) / $length;
		}

		return ($arc['sweep'] > 0 ? 1 : -1) * ($arc['radius'] - hypot($point[0] - $arc['cx'], $point[1] - $arc['cy']));
	}

	/**
	 * Where along the centre line the samples of a face or footprint lie between `from` and `to`: the ends and, on a
	 * curved wall, the joints of its pieces in between. Twin of `wallSamples()`.
	 *
	 * @return list<float>
	 */
	public function samples(float $from, float $to): array
	{
		$samples = [$from];
		$segments = $this->segments();
		$length = $this->length();
		for ($i = 1; $i < $segments; $i++) {
			$along = $length * $i / $segments;
			if ($along > $from && $along < $to) {
				$samples[] = $along;
			}
		}
		$samples[] = $to;

		return $samples;
	}

	/**
	 * Footprint of the whole wall, mitred by `ends` (`WallJoints`): its right face start → end, then its left face back.
	 * Twin of `wallOutline()`.
	 *
	 * @param array{startLeft: float, startRight: float, endLeft: float, endRight: float} $ends
	 * @return list<Point>
	 */
	public function outline(array $ends): array
	{
		$length = $this->length();
		$half = $this->thickness / 2;
		$face = function (float $across, float $start, float $end) use ($length): array {
			$samples = $this->samples(0.0, $length);
			$samples[0] = -$start;
			$samples[count($samples) - 1] = $length + $end;

			return array_map(fn (float $along): array => $this->point($along, $across), $samples);
		};

		return [
			...$face(-$half, $ends['startRight'], $ends['endRight']),
			...array_reverse($face($half, $ends['startLeft'], $ends['endLeft'])),
		];
	}
}
