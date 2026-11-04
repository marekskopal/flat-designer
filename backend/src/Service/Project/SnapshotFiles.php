<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

/**
 * What a snapshot refers to among the project's files, which stay on their instance: an example project goes without
 * them (`withoutFiles()`), a project file brings them along, uploaded anew under ids of their own (`withFiles()`).
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
		return self::withFiles($data, []);
	}

	/**
	 * The snapshot with the file ids of the floors' underlays and pictures and the items' image parameters put onto the
	 * files uploaded for it; what refers to a file not among them is left out, as `withoutFiles()` does.
	 *
	 * @param array<mixed> $data the decoded snapshot
	 * @param array<int, int> $fileIds the uploaded file's id by the snapshot's
	 * @return array<mixed>
	 */
	public static function withFiles(array $data, array $fileIds): array
	{
		return SnapshotJson::mapFloors($data, static function (array $entry) use ($fileIds): array {
			$images = self::imageParameters(SnapshotJson::arrays($entry['definitions'] ?? null));
			$underlay = is_array($entry['underlay'] ?? null) ? $entry['underlay'] : null;
			$underlayFile = is_int($underlay['fileId'] ?? null) ? $fileIds[$underlay['fileId']] ?? null : null;
			$entry['underlay'] = $underlay === null || $underlayFile === null ? null : ['fileId' => $underlayFile] + $underlay;
			$pictures = is_array($entry['pictures'] ?? null) ? $entry['pictures'] : [];
			$entry['pictures'] = array_values(array_filter(
				array_map(static fn (mixed $id): ?int => is_int($id) ? $fileIds[$id] ?? null : null, $pictures),
				is_int(...),
			));

			return SnapshotJson::mapItems($entry, static fn (array $item): array => self::withImages($item, $images, $fileIds));
		});
	}

	/**
	 * @param array<mixed> $item
	 * @param array<int, list<string>> $images image parameter keys by definition id
	 * @param array<int, int> $fileIds
	 * @return array<mixed>
	 */
	private static function withImages(array $item, array $images, array $fileIds): array
	{
		$params = SnapshotJson::array($item, 'params');
		$values = SnapshotJson::array($params, 'values');
		$definitionId = $item['definitionId'] ?? null;
		$keys = is_int($definitionId) ? $images[$definitionId] ?? [] : [];
		if ($keys === [] || $values === []) {
			return $item;
		}
		foreach ($keys as $key) {
			$fileId = is_int($values[$key] ?? null) ? $fileIds[$values[$key]] ?? null : null;
			if ($fileId === null) {
				unset($values[$key]);
			} else {
				$values[$key] = $fileId;
			}
		}
		$params['values'] = $values;
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
