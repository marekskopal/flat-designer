<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * The top of a wall under the roofs of its floor (FD-38): its height, cut by the roofs' underside where that is lower,
 * or with `upToRoof` the underside wherever a roof is over it (a gable). The viewer draws walls so (`wallTopAt()` in
 * `viewer/engine/under-roof.ts`), over both faces; here the lower face bounds the openings.
 */
final readonly class WallTop
{
	/** mm between the points along the centre line at which the top is looked at. */
	private const int Step = 25;

	/** @param list<RoofGeometry> $roofs */
	public function __construct(private WallLine $line, private int $height, private bool $upToRoof, private array $roofs)
	{
	}

	/** The lower of the two faces' tops at `along`: along a slope the downhill face is the lower. */
	public function at(float $along): float
	{
		$half = $this->line->thickness / 2;

		return min($this->faceAt($along, $half), $this->faceAt($along, -$half));
	}

	private function faceAt(float $along, float $across): float
	{
		[$x, $y] = $this->line->point($along, $across);
		$underside = RoofGeometry::undersideOf($this->roofs, $x, $y);
		if ($underside === null) {
			return $this->height;
		}

		return $this->upToRoof ? $underside : min($this->height, $underside);
	}

	/** Whether a roof is over the wall at any point of `from`–`to`. */
	public function underRoof(float $from, float $to): bool
	{
		for ($along = $from; $along <= $to; $along += self::Step) {
			[$x, $y] = $this->line->point($along, 0.0);
			if (RoofGeometry::undersideOf($this->roofs, $x, $y) !== null) {
				return true;
			}
		}

		return false;
	}

	/** The lowest top over `from`–`to` along the centre line, whole mm (the wall's height without roofs). */
	public function lowestOver(float $from, float $to): int
	{
		if ($this->roofs === []) {
			return $this->height;
		}
		$lowest = min($this->at($from), $this->at($to));
		for ($along = $from + self::Step; $along < $to; $along += self::Step) {
			$lowest = min($lowest, $this->at($along));
		}

		return (int) floor($lowest + 1e-6);
	}

	/** The highest top anywhere along the wall, whole mm: what an opening's height may reach at most. */
	public function highest(): int
	{
		if ($this->roofs === []) {
			return $this->height;
		}
		$highest = $this->height;
		$length = $this->line->length();
		for ($along = 0.0; $along <= $length; $along += self::Step) {
			$highest = max($highest, $this->at($along));
		}

		return (int) floor(max($highest, $this->at($length)) + 1e-6);
	}
}
