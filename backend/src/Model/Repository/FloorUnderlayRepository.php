<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\FloorUnderlay;

/** @extends ARepository<FloorUnderlay> */
final class FloorUnderlayRepository extends ARepository
{
	public function findByFloor(int $floorId): ?FloorUnderlay
	{
		return $this->findOne(['floor_id' => $floorId]);
	}

	/** @return list<FloorUnderlay> */
	public function findByFile(int $fileId): array
	{
		return $this->select()->where(['file_id' => $fileId])->fetchAll();
	}
}
