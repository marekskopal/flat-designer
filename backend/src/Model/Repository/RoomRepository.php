<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Room;

/** @extends ARepository<Room> */
final class RoomRepository extends ARepository
{
	/** @return list<Room> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $roomId): ?Room
	{
		return $this->findOne(['id' => $roomId, 'floor_id' => $floorId]);
	}
}
