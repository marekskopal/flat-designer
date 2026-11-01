<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\SectionLine;

/** @extends ARepository<SectionLine> */
final class SectionLineRepository extends ARepository
{
	/** @return list<SectionLine> not deleted */
	public function findByFloor(int $floorId): array
	{
		return $this->select()
			->where(['floor_id' => $floorId, 'deleted_at' => null])
			->orderBy('id', 'ASC')
			->fetchAll();
	}

	/** Including a deleted one, for undo and redo. */
	public function findOnFloor(int $floorId, int $elementId): ?SectionLine
	{
		return $this->findOne(['id' => $elementId, 'floor_id' => $floorId]);
	}
}
