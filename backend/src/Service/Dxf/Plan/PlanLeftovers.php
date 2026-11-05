<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Service\Dxf\DxfArc;
use function count;
use function sprintf;

/**
 * The wall layers' lines and arcs that made no wall, placed on the plan for the preview: faces less than half paired
 * that do not lie within a wall (as jambs do), and the arcs no curved wall took (as short straight pieces).
 */
final class PlanLeftovers
{
	public const int MaxLines = 5000;

	/** @var list<array{int, int, int, int}> */
	public array $lines = [];

	private int $count = 0;

	/**
	 * @param list<DxfArc> $looseArcs
	 * @param list<WallPiece> $walls
	 */
	public function __construct(private readonly Placement $placement, FaceSet $faces, array $looseArcs, private readonly array $walls)
	{
		foreach ($faces->faces as $face) {
			if ($face->usedLength() < 0.5 * $face->length() && !$this->inWall($face)) {
				$this->count++;
				$this->add($face->frame->point($face->t0, $face->c), $face->frame->point($face->t1, $face->c));
			}
		}
		foreach ($looseArcs as $arc) {
			$this->count++;
			$points = $arc->samples(8);
			for ($i = 1; $i < count($points); $i++) {
				$this->add($points[$i - 1], $points[$i]);
			}
		}
	}

	/** @return list<string> */
	public function warnings(bool $hasWalls): array
	{
		return $this->count > 0 && $hasWalls ? [sprintf('%d line(s) of the wall layers made no wall.', $this->count)] : [];
	}

	/**
	 * @param array{float, float} $from
	 * @param array{float, float} $to
	 */
	private function add(array $from, array $to): void
	{
		if (count($this->lines) >= self::MaxLines) {
			return;
		}
		[$ax, $ay] = $this->placement->round(...$from);
		[$bx, $by] = $this->placement->round(...$to);
		$this->lines[] = [$ax, $ay, $bx, $by];
	}

	/** Whether the face lies within a wall's footprint, as the jambs at its ends and openings do. */
	private function inWall(Face $face): bool
	{
		[$ax, $ay] = $face->frame->point($face->t0, $face->c);
		[$bx, $by] = $face->frame->point($face->t1, $face->c);
		foreach ($this->walls as $wall) {
			if ($wall->contains($ax, $ay, 2.0) && $wall->contains($bx, $by, 2.0)) {
				return true;
			}
		}

		return false;
	}
}
