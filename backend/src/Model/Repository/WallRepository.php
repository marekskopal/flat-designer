<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Wall;

/** @extends ARepository<Wall> */
final class WallRepository extends ARepository
{
	/** @return list<Wall> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $wallId): ?Wall
	{
		return $this->findOne(['id' => $wallId, 'floor_id' => $floorId]);
	}
}
