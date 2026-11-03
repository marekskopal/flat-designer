<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

use Izbik\Model\Repository\MaterialRepository;

/**
 * The materials of a snapshot from another instance, matched by code (from the snapshot's `materials`): every
 * `materialId` / `…MaterialId` and a Custom item's slot materials take this instance's id, none where it has no such
 * material, and a finish band or slot without one is dropped. The definitions name theirs by code and are left as they
 * are.
 */
final readonly class SnapshotMaterials
{
	private const array FinishKeys = ['wallFinishes', 'leftFinishes', 'rightFinishes'];

	public function __construct(private MaterialRepository $materialRepository)
	{
	}

	/**
	 * @param array<mixed> $data the decoded snapshot
	 * @return array<mixed>
	 */
	public function localize(array $data): array
	{
		$ids = $this->ids(SnapshotJson::arrays($data['materials'] ?? null));
		$data = SnapshotJson::mapFloors($data, static function (array $entry) use ($ids): array {
			$definitions = $entry['definitions'] ?? [];
			$entry = self::rewrite($entry, $ids);
			$entry['definitions'] = $definitions;

			return $entry;
		});
		$data['exteriorMaterialId'] = self::id($data['exteriorMaterialId'] ?? null, $ids);

		return $data;
	}

	/**
	 * @param array<mixed> $value
	 * @param array<int, int|null> $ids
	 * @return array<mixed>
	 */
	private static function rewrite(array $value, array $ids): array
	{
		foreach ($value as $key => $item) {
			if (is_string($key) && ($key === 'materialId' || str_ends_with($key, 'MaterialId'))) {
				$value[$key] = self::id($item, $ids);
			} elseif (is_array($item)) {
				$value[$key] = self::rewrite($item, $ids);
			}
			if (!is_array($value[$key]) || !in_array($key, self::FinishKeys, true)) {
				continue;
			}

			$value[$key] = array_values(
				array_filter($value[$key], static fn (mixed $band): bool => is_array($band) && $band['materialId'] !== null),
			);
		}
		// A Custom item's slot materials: slot key → material id.
		$params = SnapshotJson::array($value, 'params');
		$materials = SnapshotJson::array($params, 'materials');
		if ($materials !== []) {
			$params['materials'] = array_filter(
				array_map(static fn (mixed $id): ?int => self::id($id, $ids), $materials),
				static fn (?int $id): bool => $id !== null,
			);
			$value['params'] = $params;
		}

		return $value;
	}

	/** @param array<int, int|null> $ids */
	private static function id(mixed $id, array $ids): ?int
	{
		return is_int($id) ? $ids[$id] ?? null : null;
	}

	/**
	 * @param list<array<mixed>> $entries the snapshot's `materials` (`MaterialDto`)
	 * @return array<int, int|null> this instance's material id by the snapshot's
	 */
	private function ids(array $entries): array
	{
		$byCode = [];
		foreach ($this->materialRepository->findAllOrdered() as $material) {
			$byCode[$material->code] = $material->id;
		}
		$ids = [];
		foreach ($entries as $entry) {
			if (is_int($entry['id'] ?? null) && is_string($entry['code'] ?? null)) {
				$ids[$entry['id']] = $byCode[$entry['code']] ?? null;
			}
		}

		return $ids;
	}
}
