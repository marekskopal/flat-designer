<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use FlatDesigner\Service\Editor\StairRise;
use stdClass;

/**
 * The stairs' part of a version's scene, derived from the snapshot's floors as `SceneProvider` derives it from the rows:
 * `stairRise` (`StairRise`) and `stairsBelow` (the floor below's stairs with their rise). Snapshots taken before stairs
 * have none.
 */
final readonly class SnapshotStairs
{
	/** @var list<array{id: int, level: int, elevation: int}> */
	private array $levels;

	/** @var array<int, array{entry: stdClass, floor: stdClass, id: int, level: int}> by id */
	private array $floors;

	/**
	 * @param list<array{entry: stdClass, floor: stdClass, id: int, level: int}> $floors
	 * @param array<int, stdClass> $sceneFloors the floors as the scenes serve them (with their elevations), by id
	 */
	public function __construct(array $floors, private array $sceneFloors)
	{
		$this->floors = array_column($floors, null, 'id');
		$this->levels = array_map(
			fn (array $f): array => ['id' => $f['id'], 'level' => $f['level'], 'elevation' => $this->elevation($f)],
			$floors,
		);
	}

	/** @param array{entry: stdClass, floor: stdClass, id: int, level: int} $floor the scene's floor */
	public function addTo(stdClass $scene, array $floor): void
	{
		$scene->stairs = self::stairs($floor);
		$scene->stairRise = $this->rise($floor);
		$below = StairRise::below($floor['id'], $floor['level'], $this->levels);
		$scene->stairsBelow = $below === null ? null : [
			'floorId' => $below['id'],
			'rise' => $this->rise($this->floors[$below['id']]),
			'stairs' => self::stairs($this->floors[$below['id']])];
	}

	/** @param array{entry: stdClass, floor: stdClass, id: int, level: int} $floor */
	private function rise(array $floor): int
	{
		$heights = [];
		foreach (is_array($floor['entry']->walls ?? null) ? $floor['entry']->walls : [] as $wall) {
			if ($wall instanceof stdClass && is_int($wall->height ?? null)) {
				$heights[] = $wall->height;
			}
		}

		return StairRise::rise($floor['level'], $this->elevation($floor), $this->levels, FloorStack::tallestWall($heights));
	}

	/** @param array{entry: stdClass, floor: stdClass, id: int, level: int} $floor */
	private function elevation(array $floor): int
	{
		$elevation = $this->sceneFloors[$floor['id']]->elevation ?? null;

		return is_int($elevation) ? $elevation : 0;
	}

	/**
	 * @param array{entry: stdClass, floor: stdClass, id: int, level: int} $floor
	 * @return list<mixed>
	 */
	private static function stairs(array $floor): array
	{
		$stairs = $floor['entry']->stairs ?? null;

		return is_array($stairs) ? array_values($stairs) : [];
	}
}
