<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use Izbik\Model\Entity\RoofWindow;

/** @extends ARepository<RoofWindow> */
final class RoofWindowRepository extends ARepository
{
	/**
	 * @param list<int> $roofIds
	 * @return list<RoofWindow> not deleted
	 */
	public function findByRoofs(array $roofIds): array
	{
		if ($roofIds === []) {
			return [];
		}

		return $this->select()
			->where(['roof_id', 'IN', $roofIds])
			->where(['deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo; the caller checks the roof's floor. */
	public function findById(int $windowId): ?RoofWindow
	{
		return $this->findOne(['id' => $windowId]);
	}
}
