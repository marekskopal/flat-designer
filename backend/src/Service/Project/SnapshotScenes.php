<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use Closure;
use FlatDesigner\Service\Project\Exception\InvalidSnapshotException;
use stdClass;

/**
 * Floor scenes served straight from a decoded snapshot (`ProjectSnapshotService::getFloorScene()` /
 * `getFloorScenes()`), shaped like `SceneDto`: the floor's entry with the snapshot's materials and location, the stairs'
 * rise and the stairs coming up from below (`SnapshotStairs`), its floor
 * stacked by `FloorStack` when the snapshot predates stacked floors. Decoded to objects rather than arrays so empty JSON
 * objects (item params) stay objects when re-encoded.
 */
final class SnapshotScenes
{
	/**
	 * The scene of a floor and those of the floors under it (of a lower level), lowest first, from one decode.
	 *
	 * @param Closure(): SnapshotReader $read the snapshot read as arrays, for stacking an older one's floors
	 * @return array{scene: ?stdClass, below: list<stdClass>}
	 */
	public static function of(mixed $data, int $floorId, Closure $read): array
	{
		if (!$data instanceof stdClass || !is_array($data->floors ?? null)) {
			throw new InvalidSnapshotException('Snapshot has no floors.');
		}

		$floors = self::floors($data->floors);
		$level = null;
		foreach ($floors as $floor) {
			$level = $floor['id'] === $floorId ? $floor['level'] : $level;
		}
		$chosen = array_filter($floors, fn (array $f): bool => $level !== null && ($f['level'] < $level || $f['id'] === $floorId));
		usort($chosen, fn (array $a, array $b): int => $a['level'] <=> $b['level']);
		// An older snapshot's floors are stacked once, for all the scenes that need it.
		$stack = null;
		$stacked = function () use (&$stack, $read): array {
			$stack ??= FloorStack::ofSnapshot($read());

			return $stack;
		};

		$sceneFloors = [];
		foreach ($floors as $f) {
			$id = $f['id'];
			$sceneFloors[$id] = FloorStack::sceneFloor($f['floor'], fn (): array => $stacked()[$id]);
		}
		$stairs = new SnapshotStairs($floors, $sceneFloors);

		$result = ['scene' => null, 'below' => []];
		foreach ($chosen as $f) {
			$scene = clone $f['entry'];
			$id = $f['id'];
			$scene->floor = $sceneFloors[$id];
			$scene->materials = $data->materials ?? [];
			$scene->location = $data->location ?? null;
			// Missing in snapshots taken before façades (FD-88).
			$scene->exteriorMaterialId = $data->exteriorMaterialId ?? null;
			$stairs->addTo($scene, $f);
			if ($id === $floorId) {
				$result['scene'] = $scene;
			} else {
				$result['below'][] = $scene;
			}
		}

		return $result;
	}

	/**
	 * @param array<mixed> $entries the snapshot's `floors`
	 * @return list<array{entry: stdClass, floor: stdClass, id: int, level: int}>
	 */
	private static function floors(array $entries): array
	{
		$floors = [];
		foreach ($entries as $entry) {
			$floor = $entry instanceof stdClass ? ($entry->floor ?? null) : null;
			if ($entry instanceof stdClass && $floor instanceof stdClass && is_int($floor->id ?? null) && is_int($floor->level ?? null)) {
				$floors[] = ['entry' => $entry, 'floor' => $floor, 'id' => $floor->id, 'level' => $floor->level];
			}
		}

		return $floors;
	}
}
