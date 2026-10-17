<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Item;

/** @extends ARepository<Item> */
final class ItemRepository extends ARepository
{
	/** @return list<Item> */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId])
			->orderBy('id', 'ASC')
			->fetchAll();
	}
}
