<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Project;

/** @extends ARepository<Project> */
final class ProjectRepository extends ARepository
{
	public function findById(int $projectId): ?Project
	{
		return $this->findOne(['id' => $projectId]);
	}

	/** @return list<Project> */
	public function findAllOrdered(): array
	{
		return $this->select()->orderBy('updated_at', 'DESC')->fetchAll();
	}

	/** @return list<int> */
	public function findAllIds(): array
	{
		$rows = $this->select()->columns(['id'])->fetchAssocAll();

		return array_map(static fn (array $row): int => is_numeric($row['id']) ? (int) $row['id'] : 0, $rows);
	}

	/** @return list<Project> */
	public function findByOwner(int $ownerId): array
	{
		return $this->select()->where(['owner_id' => $ownerId])->orderBy('updated_at', 'DESC')->fetchAll();
	}

	/** @return list<Project> */
	public function findWithoutOwner(): array
	{
		return $this->select()->where(['owner_id' => null])->fetchAll();
	}

	public function countByOwner(int $ownerId): int
	{
		return $this->select()->where(['owner_id' => $ownerId])->count();
	}
}
