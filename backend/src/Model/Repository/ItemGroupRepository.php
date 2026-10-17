<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\ItemGroup;

/** @extends ARepository<ItemGroup> */
final class ItemGroupRepository extends ARepository
{
	/** @return list<ItemGroup> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $groupId): ?ItemGroup
	{
		return $this->findOne(['id' => $groupId, 'floor_id' => $floorId]);
	}
}
