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

	/** @return list<Item> the group's members, not deleted */
	public function findByGroup(int $groupId): array
	{
		return $this->select()
			->where(['group_id' => $groupId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $itemId): ?Item
	{
		return $this->findOne(['id' => $itemId, 'floor_id' => $floorId]);
	}

	/** Items (not deleted, in any project) drawn from the furniture definition. */
	public function countByDefinition(int $definitionId): int
	{
		return $this->select()->where(['definition_id' => $definitionId, 'deleted_at' => null])->count();
	}
}
