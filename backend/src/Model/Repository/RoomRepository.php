<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Room;

/** @extends ARepository<Room> */
final class RoomRepository extends ARepository
{
	/** @return list<Room> */
	public function findByFlat(int $flatId): array
	{
		return $this->select()
			->where(['flat_id' => $flatId])
			->orderBy('id', 'ASC')
			->fetchAll();
	}
}
