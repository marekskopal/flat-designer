<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use const INF;

/**
 * The sums `RoomUnderRoof` gathers over a room's parts under the roofs' planes.
 *
 * @phpstan-import-type Point from PlanMath
 * @phpstan-import-type CoverPiece from RoofCover
 */
final class RoomUnderRoofSum
{
	public float $covered = 0.0;

	public float $volume = 0.0;

	public float $headroom = 0.0;

	/** mm², the ceiling's own area: its sloped parts measured along the slope. */
	public float $ceiling = 0.0;

	public float $lowest = INF;

	public float $highest = -INF;

	public function __construct(private readonly int $height, private readonly int $floor)
	{
	}

	/**
	 * A part of the room under a plane: the clear height there is the underside less the floor, between 0 and the
	 * room's height.
	 *
	 * @param list<Point> $part
	 * @param CoverPiece $piece
	 */
	public function add(array $part, array $piece): void
	{
		['k' => $k, 'gx' => $gx, 'gy' => $gy] = $piece;
		[$area] = ConvexClip::moments($part);
		$this->covered += $area;
		$below = ConvexClip::clip(ConvexClip::clip($part, [$gx, $gy, $this->height + $this->floor - $k]), [-$gx, -$gy, $k - $this->floor]);
		[$la, $lx, $ly] = ConvexClip::moments($below);
		[$ha] = ConvexClip::moments(ConvexClip::clip($part, [-$gx, -$gy, $k - $this->floor - $this->height]));
		$this->volume += ($k - $this->floor) * $la + $gx * $lx + $gy * $ly + $this->height * $ha;
		// The ceiling: flat over `ha`, along the slope over `la`; none where the roof comes down to the floor.
		$this->ceiling += $ha + $la * sqrt(1 + $gx * $gx + $gy * $gy);
		if ($this->height >= RoomUnderRoof::Headroom) {
			[$headroom] = ConvexClip::moments(ConvexClip::clip($part, [-$gx, -$gy, $k - $this->floor - RoomUnderRoof::Headroom]));
			$this->headroom += $headroom;
		}
		foreach ($part as [$x, $y]) {
			$clear = min(max(RoofCover::heightAt($piece, $x, $y) - $this->floor, 0.0), (float) $this->height);
			$this->lowest = min($this->lowest, $clear);
			$this->highest = max($this->highest, $clear);
		}
	}

	/** The room's area no roof covers (mm²), at its height. */
	public function addOpen(float $area, float $none): void
	{
		if ($area <= $none) {
			return;
		}
		$this->volume += $this->height * $area;
		$this->ceiling += $area;
		$this->headroom += $this->height >= RoomUnderRoof::Headroom ? $area : 0.0;
		$this->lowest = min($this->lowest, (float) $this->height);
		$this->highest = (float) $this->height;
	}
}
