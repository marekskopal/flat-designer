<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Stair;

/** @extends ARepository<Stair> */
final class StairRepository extends ARepository
{
	/** @return list<Stair> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $stairId): ?Stair
	{
		return $this->findOne(['id' => $stairId, 'floor_id' => $floorId]);
	}
}
