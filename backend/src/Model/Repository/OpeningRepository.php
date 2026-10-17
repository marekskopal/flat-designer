<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Opening;

/** @extends ARepository<Opening> */
final class OpeningRepository extends ARepository
{
	/**
	 * @param list<int> $wallIds
	 * @return list<Opening>
	 */
	public function findByWalls(array $wallIds): array
	{
		if ($wallIds === []) {
			return [];
		}

		return $this->select()
			->where(['wall_id', 'IN', $wallIds])
			->orderBy('offset', 'ASC')
			->fetchAll();
	}
}
