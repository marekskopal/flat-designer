<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf\Plan;

use Izbik\Service\Dxf\DxfArc;
use Izbik\Service\Dxf\DxfSegment;
use function array_keys;
use function floor;
use function max;
use function min;

/**
 * The opening layers' lines and arcs, grouped where their boxes touch (a door's leaf and swing, a window's frame and
 * glazing): a union-find over a grid of 1 m cells.
 */
final class EntityGroups
{
	private const float Gap = 20.0;

	private const float Cell = 1000.0;

	/** Cells a box may cover before it counts as a line across the drawing, which belongs to no opening. */
	private const int MaxCells = 400;

	/** @var list<DxfSegment|DxfArc> */
	public array $entities = [];

	/** @var list<array{float, float, float, float}> the entities' boxes */
	public array $boxes = [];

	/** @var array<int, int> */
	private array $parents = [];

	/** @var array<int, array<int, list<int>>> */
	private array $cells = [];

	/**
	 * @param list<DxfSegment> $segments
	 * @param list<DxfArc> $arcs
	 */
	public function __construct(array $segments, array $arcs)
	{
		foreach ($segments as $segment) {
			$this->add($segment, self::segmentBox($segment));
		}
		foreach ($arcs as $arc) {
			$this->add($arc, self::arcBox($arc));
		}
	}

	public function root(int $index): int
	{
		while ($this->parents[$index] !== $index) {
			$this->parents[$index] = $this->parents[$this->parents[$index]];
			$index = $this->parents[$index];
		}

		return $index;
	}

	/**
	 * The entities of the groups.
	 *
	 * @param array<int, true> $roots
	 * @return list<DxfSegment|DxfArc>
	 */
	public function members(array $roots): array
	{
		$members = [];
		foreach ($this->entities as $index => $entity) {
			if (isset($roots[$this->root($index)])) {
				$members[] = $entity;
			}
		}

		return $members;
	}

	/**
	 * Every group but the given ones.
	 *
	 * @param array<int, true> $except roots
	 * @return list<list<DxfSegment|DxfArc>>
	 */
	public function groups(array $except): array
	{
		$groups = [];
		foreach (array_keys($this->entities) as $index) {
			$root = $this->root($index);
			if (!isset($except[$root])) {
				$groups[$root][] = $this->entities[$index];
			}
		}

		return array_values($groups);
	}

	/** @param array{float, float, float, float} $box */
	private function add(DxfSegment|DxfArc $entity, array $box): void
	{
		$index = count($this->entities);
		$this->entities[] = $entity;
		$this->boxes[] = $box;
		$this->parents[$index] = $index;

		$x0 = (int) floor(($box[0] - self::Gap) / self::Cell);
		$x1 = (int) floor(($box[2] + self::Gap) / self::Cell);
		$y0 = (int) floor(($box[1] - self::Gap) / self::Cell);
		$y1 = (int) floor(($box[3] + self::Gap) / self::Cell);
		if (($x1 - $x0 + 1) * ($y1 - $y0 + 1) > self::MaxCells) {
			return;
		}
		for ($x = $x0; $x <= $x1; $x++) {
			for ($y = $y0; $y <= $y1; $y++) {
				$this->joinCell($index, $x, $y);
			}
		}
	}

	private function joinCell(int $index, int $x, int $y): void
	{
		foreach ($this->cells[$x][$y] ?? [] as $other) {
			if (self::touch($this->boxes[$index], $this->boxes[$other])) {
				$this->parents[$this->root($index)] = $this->root($other);
			}
		}
		$this->cells[$x][$y][] = $index;
	}

	/**
	 * @param array{float, float, float, float} $a
	 * @param array{float, float, float, float} $b
	 */
	private static function touch(array $a, array $b): bool
	{
		return $a[0] <= $b[2] + self::Gap && $b[0] <= $a[2] + self::Gap && $a[1] <= $b[3] + self::Gap && $b[1] <= $a[3] + self::Gap;
	}

	/** @return array{float, float, float, float} */
	private static function segmentBox(DxfSegment $segment): array
	{
		return [
			min($segment->x1, $segment->x2),
			min($segment->y1, $segment->y2),
			max($segment->x1, $segment->x2), max($segment->y1, $segment->y2)];
	}

	/** @return array{float, float, float, float} */
	private static function arcBox(DxfArc $arc): array
	{
		$points = $arc->samples(16);
		$box = [$points[0][0], $points[0][1], $points[0][0], $points[0][1]];
		foreach ($points as [$x, $y]) {
			$box = [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
		}

		return $box;
	}
}
