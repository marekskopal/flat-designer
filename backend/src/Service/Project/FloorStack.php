<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use Closure;
use FlatDesigner\Service\Editor\Element\WallHandler;
use stdClass;

/**
 * How the floors of a project stack: a floor's `elevation` is its finished floor level above the project datum (±0.000),
 * its `slabThickness` the slab under it. A floor stands on the walls of the floor below, so the default elevation of a
 * floor is the one below's elevation, its tallest wall and the floor's own slab; a floor below the others hangs the same
 * way under the one above.
 *
 * @phpstan-type StackedFloor array{level: int, elevation: int, slabThickness: int, tallestWall: int}
 */
final class FloorStack
{
	/** mm, the wall height of a floor without walls (the walls' default). */
	public const int DefaultWallHeight = WallHandler::DefaultHeight;

	/** mm, the slab on the ground under the ground floor and the basements. */
	public const int GroundSlab = 300;

	/** mm, the slab between two storeys. */
	public const int UpperSlab = 250;

	/** @param list<int> $wallHeights mm, of a floor's walls */
	public static function tallestWall(array $wallHeights): int
	{
		return $wallHeights === [] ? self::DefaultWallHeight : max($wallHeights);
	}

	public static function defaultSlab(int $level): int
	{
		return $level <= 0 ? self::GroundSlab : self::UpperSlab;
	}

	/**
	 * The elevation a floor at `$level` takes among the other floors: level with a floor of its level, on the nearest
	 * floor below, else under the nearest one above, else at the datum.
	 *
	 * @param list<StackedFloor> $others
	 */
	public static function elevation(int $level, int $slabThickness, array $others, int $tallestWall = self::DefaultWallHeight): int
	{
		// The top of the nearest floor below and the underside of the nearest floor above, by [level, height].
		$below = null;
		$above = null;
		foreach ($others as $floor) {
			if ($floor['level'] === $level) {
				return $floor['elevation'];
			}
			if ($floor['level'] < $level) {
				$top = [$floor['level'], $floor['elevation'] + $floor['tallestWall']];
				$below = $below === null || $top > $below ? $top : $below;
			} else {
				$underside = [$floor['level'], $floor['elevation'] - $floor['slabThickness']];
				$above = $above === null || $underside < $above ? $underside : $above;
			}
		}

		return match (true) {
			$below !== null => $below[1] + $slabThickness,
			$above !== null => $above[1] - $tallestWall,
			default => 0,
		};
	}

	/**
	 * Stacks floors that have no elevation yet: the one nearest the ground floor (level 0) at the datum, or a storey of
	 * default walls per level above it, then the others outwards from it, each with its default slab.
	 *
	 * @param array<int, array{level: int, tallestWall: int}> $floors by id
	 * @return array<int, array{elevation: int, slabThickness: int}> by id
	 */
	public static function stack(array $floors): array
	{
		if ($floors === []) {
			return [];
		}

		$levels = array_unique(array_map(fn (array $f): int => $f['level'], $floors));
		usort($levels, fn (int $a, int $b): int => [abs($a), -$a] <=> [abs($b), -$b]);
		$anchor = $levels[0];
		$anchorElevation = $anchor * (self::DefaultWallHeight + self::UpperSlab);

		// Outwards from the anchor: up in ascending order, then down in descending order.
		$order = array_keys($floors);
		usort($order, function (int $a, int $b) use ($floors, $anchor): int {
			$la = $floors[$a]['level'];
			$lb = $floors[$b]['level'];

			return [$la < $anchor, $la < $anchor ? -$la : $la, $a] <=> [$lb < $anchor, $lb < $anchor ? -$lb : $lb, $b];
		});

		$result = [];
		/** @var list<StackedFloor> $placed */
		$placed = [];
		foreach ($order as $id) {
			$floor = $floors[$id];
			$slab = self::defaultSlab($floor['level']);
			$elevation = $floor['level'] === $anchor
				? $anchorElevation
				: self::elevation($floor['level'], $slab, $placed, $floor['tallestWall']);
			$result[$id] = ['elevation' => $elevation, 'slabThickness' => $slab];
			$placed[] = ['level' => $floor['level'], 'elevation' => $elevation, 'slabThickness' => $slab, 'tallestWall' => $floor['tallestWall']];
		}

		return $result;
	}

	/**
	 * A snapshot's floor as the scene serves it: as stored, or, from a snapshot taken before stacked floors, a copy with
	 * the elevation and slab `$stack` gives (called only then, as it decodes the snapshot again).
	 *
	 * @param Closure(): array{elevation: int, slabThickness: int} $stack
	 */
	public static function sceneFloor(stdClass $floor, Closure $stack): stdClass
	{
		if (is_int($floor->elevation ?? null) && is_int($floor->slabThickness ?? null)) {
			return $floor;
		}

		$stacked = clone $floor;
		['elevation' => $stacked->elevation, 'slabThickness' => $stacked->slabThickness] = $stack();

		return $stacked;
	}

	/**
	 * Elevation and slab of each floor of a version's snapshot by its id there: as stored, or stacked from the levels and
	 * walls in a snapshot taken before stacked floors.
	 *
	 * @return array<int, array{elevation: int, slabThickness: int}>
	 */
	public static function ofSnapshot(SnapshotReader $data): array
	{
		$entries = $data->objects('floors');
		$stored = [];
		foreach ($entries as $entry) {
			$floor = $entry->object('floor');
			$elevation = $floor->nullableInt('elevation');
			$slab = $floor->nullableInt('slabThickness');
			if ($elevation === null || $slab === null) {
				$stored = null;
				break;
			}
			$stored[$floor->int('id')] = ['elevation' => $elevation, 'slabThickness' => $slab];
		}
		if ($stored !== null) {
			return $stored;
		}

		// An older snapshot: its walls are read only then.
		$floors = [];
		foreach ($entries as $entry) {
			$floors[$entry->object('floor')->int('id')] = [
				'level' => $entry->object('floor')->int('level'),
				'tallestWall' => self::tallestWall(
					array_map(fn (SnapshotReader $wall): int => $wall->int('height'), $entry->objects('walls')),
				),
			];
		}

		return self::stack($floors);
	}
}
