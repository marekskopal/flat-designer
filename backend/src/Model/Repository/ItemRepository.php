<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Item;

/** @extends ARepository<Item> */
final class ItemRepository extends ARepository
{
	/** @return list<Item> */
	public function findByFlat(int $flatId): array
	{
		return $this->select()
			->where(['flat_id' => $flatId])
			->orderBy('id', 'ASC')
			->fetchAll();
	}
}
