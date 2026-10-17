<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\VariantSet;

/** @extends ARepository<VariantSet> */
final class VariantSetRepository extends ARepository
{
	/** @return list<VariantSet> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $setId): ?VariantSet
	{
		return $this->findOne(['id' => $setId, 'floor_id' => $floorId]);
	}
}
