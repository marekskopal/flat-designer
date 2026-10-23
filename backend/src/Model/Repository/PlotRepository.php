<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Plot;

/** @extends ARepository<Plot> */
final class PlotRepository extends ARepository
{
	/** @return list<Plot> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $plotId): ?Plot
	{
		return $this->findOne(['id' => $plotId, 'floor_id' => $floorId]);
	}
}
