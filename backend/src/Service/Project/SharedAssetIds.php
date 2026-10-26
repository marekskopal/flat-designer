<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use stdClass;

/**
 * The models and pictures a shared floor draws, remembered per snapshot: a guest's viewer asks for each asset in its
 * own request, and decoding the whole version for every one is wasteful. Keyed by the version's id: a version never
 * changes once saved, so the cache cannot go stale; the worker keeps it across requests.
 */
final class SharedAssetIds
{
	private const int MaxEntries = 64;

	/** @var array<string, array{models: list<int>, pictures: list<int>}> */
	private array $entries = [];

	/**
	 * @param callable(): ?stdClass $scene decodes the floor scene when it is not remembered yet
	 * @return array{models: list<int>, pictures: list<int>}|null null when the floor is gone from the snapshot
	 */
	public function of(int $versionId, int $floorId, callable $scene): ?array
	{
		return $this->remember($versionId . ':' . $floorId, function () use ($scene): ?array {
			$decoded = $scene();

			return $decoded === null ? null : self::assetsOf([$decoded]);
		});
	}

	/**
	 * The models and pictures of the floors under a shared floor (the all floors view), remembered the same way.
	 *
	 * @param callable(): list<stdClass> $scenes decodes their scenes when they are not remembered yet
	 * @return array{models: list<int>, pictures: list<int>}
	 */
	public function below(int $versionId, int $floorId, callable $scenes): array
	{
		return $this->remember(
			$versionId . ':' . $floorId . ':below',
			fn (): array => self::assetsOf($scenes()),
		) ?? ['models' => [], 'pictures' => []];
	}

	/**
	 * The entry under `$key`, computed by `$compute` when it is not remembered yet (null is not remembered); the oldest
	 * entry goes once there are `MaxEntries`.
	 *
	 * @param callable(): ?array{models: list<int>, pictures: list<int>} $compute
	 * @return array{models: list<int>, pictures: list<int>}|null
	 */
	private function remember(string $key, callable $compute): ?array
	{
		if (!isset($this->entries[$key])) {
			$entry = $compute();
			if ($entry === null) {
				return null;
			}
			if (count($this->entries) >= self::MaxEntries) {
				array_shift($this->entries);
			}
			$this->entries[$key] = $entry;
		}

		return $this->entries[$key];
	}

	/**
	 * @param list<stdClass> $scenes
	 * @return array{models: list<int>, pictures: list<int>}
	 */
	private static function assetsOf(array $scenes): array
	{
		return [
			'models' => array_merge([], ...array_map(fn (stdClass $s): array => self::ids($s->models ?? null), $scenes)),
			'pictures' => array_merge([], ...array_map(fn (stdClass $s): array => self::ints($s->pictures ?? null), $scenes)),
		];
	}

	/** Forgets everything, for tests that reuse version ids. */
	public function clear(): void
	{
		$this->entries = [];
	}

	/** @return list<int> the ids of a list of objects */
	private static function ids(mixed $objects): array
	{
		$ids = [];
		foreach (is_array($objects) ? $objects : [] as $object) {
			if (is_object($object) && is_int($object->id ?? null)) {
				$ids[] = $object->id;
			}
		}

		return $ids;
	}

	/** @return list<int> */
	private static function ints(mixed $values): array
	{
		return array_values(array_filter(is_array($values) ? $values : [], is_int(...)));
	}
}
