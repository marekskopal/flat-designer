<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Wall;

/** @extends ARepository<Wall> */
final class WallRepository extends ARepository
{
	/** @return list<Wall> */
	public function findByFlat(int $flatId): array
	{
		return $this->select()
			->where(['flat_id' => $flatId])
			->orderBy('id', 'ASC')
			->fetchAll();
	}
}
