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
}
