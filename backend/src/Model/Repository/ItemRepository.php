<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Item;

/** @extends ARepository<Item> */
final class ItemRepository extends ARepository
{
	/** @return list<Item> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $itemId): ?Item
	{
		return $this->findOne(['id' => $itemId, 'floor_id' => $floorId]);
	}
}
