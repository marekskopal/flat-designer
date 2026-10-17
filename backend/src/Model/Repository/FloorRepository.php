<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Floor;

/** @extends ARepository<Floor> */
final class FloorRepository extends ARepository
{
	public function findOneInProject(int $projectId, int $floorId): ?Floor
	{
		return $this->findOne(['id' => $floorId, 'project_id' => $projectId]);
	}

	/** @return list<Floor> */
	public function findByProject(int $projectId): array
	{
		return $this->select()
			->where(['project_id' => $projectId])
			->orderBy('level', 'ASC')
			->orderBy('id', 'ASC')
			->fetchAll();
	}
}
