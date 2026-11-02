<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use Izbik\Model\Entity\FloorVoid;

/** @extends ARepository<FloorVoid> */
final class FloorVoidRepository extends ARepository
{
	/** @return list<FloorVoid> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $elementId): ?FloorVoid
	{
		return $this->findOne(['id' => $elementId, 'floor_id' => $floorId]);
	}
}
