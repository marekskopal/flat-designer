<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

/**
 * What a snapshot refers to among the project's files, which stay on their instance: an example project or a project
 * moved to another instance goes without them (`withoutFiles()`).
 */
final readonly class SnapshotFiles
{
	/**
	 * The snapshot without the floors' underlays and pictures and the items' image parameters (their pictures then
	 * show none).
	 *
	 * @param array<mixed> $data the decoded snapshot
	 * @return array<mixed>
	 */
	public static function withoutFiles(array $data): array
	{
		return SnapshotJson::mapFloors($data, static function (array $entry): array {
			$images = self::imageParameters(SnapshotJson::arrays($entry['definitions'] ?? null));
			$entry['underlay'] = null;
			$entry['pictures'] = [];

			return SnapshotJson::mapItems($entry, static fn (array $item): array => self::withoutImages($item, $images));
		});
	}

	/**
	 * @param array<mixed> $item
	 * @param array<int, list<string>> $images image parameter keys by definition id
	 * @return array<mixed>
	 */
	private static function withoutImages(array $item, array $images): array
	{
		$params = SnapshotJson::array($item, 'params');
		$values = SnapshotJson::array($params, 'values');
		$definitionId = $item['definitionId'] ?? null;
		$keys = is_int($definitionId) ? $images[$definitionId] ?? [] : [];
		if ($keys === [] || $values === []) {
			return $item;
		}
		$params['values'] = array_diff_key($values, array_flip($keys));
		$item['params'] = $params;

		return $item;
	}

	/**
	 * @param list<array<mixed>> $definitions the floor's `definitions` (`SceneDefinitionDto`)
	 * @return array<int, list<string>> the keys of each definition's image parameters, by its id in the snapshot
	 */
	private static function imageParameters(array $definitions): array
	{
		$images = [];
		foreach ($definitions as $entry) {
			$parameters = SnapshotJson::arrays(SnapshotJson::array($entry, 'definition')['parameters'] ?? null);
			$keys = array_column(array_filter($parameters, static fn (array $p): bool => ($p['type'] ?? null) === 'image'), 'key');
			if (is_int($entry['id'] ?? null) && $keys !== []) {
				$images[$entry['id']] = array_values(array_filter($keys, is_string(...)));
			}
		}

		return $images;
	}
}
