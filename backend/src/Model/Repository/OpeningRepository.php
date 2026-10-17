<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Opening;

/** @extends ARepository<Opening> */
final class OpeningRepository extends ARepository
{
	/**
	 * @param list<int> $wallIds
	 * @return list<Opening> not deleted
	 */
	public function findByWalls(array $wallIds): array
	{
		if ($wallIds === []) {
			return [];
		}

		return $this->select()
			->where(['wall_id', 'IN', $wallIds])
			->where(['deleted_at' => null])
			->orderBy('offset', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo; the caller checks the wall's floor. */
	public function findById(int $openingId): ?Opening
	{
		return $this->findOne(['id' => $openingId]);
	}
}
