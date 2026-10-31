<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Roof;

/** @extends ARepository<Roof> */
final class RoofRepository extends ARepository
{
	/** @return list<Roof> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/**
	 * The roofs (not deleted) of the floors, in one query.
	 *
	 * @param list<int> $floorIds
	 * @return list<Roof>
	 */
	public function findByFloors(array $floorIds): array
	{
		if ($floorIds === []) {
			return [];
		}

		return $this->select()
			->where(['floor_id', 'IN', $floorIds])
			->where(['deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $roofId): ?Roof
	{
		return $this->findOne(['id' => $roofId, 'floor_id' => $floorId]);
	}
}
