<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Wall;

/** @extends ARepository<Wall> */
final class WallRepository extends ARepository
{
	/** @return list<Wall> */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId])
			->orderBy('id', 'ASC')
			->fetchAll();
	}
}
