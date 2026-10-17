<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Room;

/** @extends ARepository<Room> */
final class RoomRepository extends ARepository
{
	/** @return list<Room> */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId])
			->orderBy('id', 'ASC')
			->fetchAll();
	}
}
