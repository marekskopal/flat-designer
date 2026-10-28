<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

/**
 * The bottom of a wall (FD-87): on the floor's level, at `baseStart` sloping straight to `baseEnd`, or with `onGround`
 * the ground under its centre line (`Ground`). Openings stand on it: their sills may not be lower. The viewer draws
 * walls so (`wallBottomAt()` in `viewer/engine/under-roof.ts`).
 */
final readonly class WallBottom
{
	/** mm between the points along the centre line at which the bottom is looked at. */
	private const int Step = 25;

	public function __construct(private WallLine $line, private int $baseStart, private int $baseEnd, private ?Ground $ground)
	{
	}

	/** The bottom at `along` the centre line, mm against the floor's level. */
	public function at(float $along): float
	{
		if ($this->ground !== null) {
			[$x, $y] = $this->line->point($along, 0.0);

			return $this->ground->at($x, $y);
		}
		$length = $this->line->length();
		$t = $length > 0 ? max(0.0, min(1.0, $along / $length)) : 0.0;

		return $this->baseStart + ($this->baseEnd - $this->baseStart) * $t;
	}

	/** The highest bottom over `from`–`to` along the centre line, whole mm: what an opening's sill has to reach. */
	public function highestOver(float $from, float $to): int
	{
		if ($this->ground === null && $this->baseStart === 0 && $this->baseEnd === 0) {
			return 0;
		}
		$highest = max($this->at($from), $this->at($to));
		for ($along = $from + self::Step; $along < $to; $along += self::Step) {
			$highest = max($highest, $this->at($along));
		}

		return (int) ceil($highest - 1e-6);
	}
}
