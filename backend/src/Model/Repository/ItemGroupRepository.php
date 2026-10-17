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

	/** @return list<ItemGroup> the variants of a set, not deleted */
	public function findBySet(int $setId): array
	{
		return $this->select()
			->where(['variant_set_id' => $setId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $groupId): ?ItemGroup
	{
		return $this->findOne(['id' => $groupId, 'floor_id' => $floorId]);
	}
}
