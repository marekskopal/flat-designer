<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Railing;

/** @extends ARepository<Railing> */
final class RailingRepository extends ARepository
{
	/** @return list<Railing> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $elementId): ?Railing
	{
		return $this->findOne(['id' => $elementId, 'floor_id' => $floorId]);
	}
}
