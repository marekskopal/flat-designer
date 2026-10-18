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

	/**
	 * The projects of the floors, each once.
	 *
	 * @param list<int> $floorIds
	 * @return list<int>
	 */
	public function findProjectIds(array $floorIds): array
	{
		if ($floorIds === []) {
			return [];
		}

		$rows = $this->select()->columns(['project_id'])->where(['id', 'IN', $floorIds])->groupBy(['project_id'])->fetchAssocAll();

		return array_map(static fn (array $row): int => is_numeric($row['project_id']) ? (int) $row['project_id'] : 0, $rows);
	}
}
