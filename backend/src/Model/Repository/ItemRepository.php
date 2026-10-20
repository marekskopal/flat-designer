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

	/**
	 * The items (not deleted) of the floors drawn from a furniture definition, in one query.
	 *
	 * @param list<int> $floorIds
	 * @return list<Item>
	 */
	public function findWithDefinitionOnFloors(array $floorIds): array
	{
		if ($floorIds === []) {
			return [];
		}

		return $this->select()
			->where(['floor_id', 'IN', $floorIds])
			->where(['deleted_at' => null])
			->where(['definition_id', '!=', null])
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

	/**
	 * Floors with items (not deleted) drawn from the furniture definition.
	 *
	 * @return list<int>
	 */
	public function findFloorIdsByDefinition(int $definitionId): array
	{
		$rows = $this->select()
			->columns(['floor_id'])
			->where(['definition_id' => $definitionId, 'deleted_at' => null])
			->groupBy(['floor_id'])
			->fetchAssocAll();

		return array_map(static fn (array $row): int => is_numeric($row['floor_id']) ? (int) $row['floor_id'] : 0, $rows);
	}
}
