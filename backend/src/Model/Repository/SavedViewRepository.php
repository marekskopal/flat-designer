<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\SavedView;

/** @extends ARepository<SavedView> */
final class SavedViewRepository extends ARepository
{
	public function findOneInProject(int $projectId, int $viewId): ?SavedView
	{
		return $this->findOne(['id' => $viewId, 'project_id' => $projectId]);
	}

	/** @return list<SavedView> by name */
	public function findByProject(int $projectId): array
	{
		return $this->select()->where(['project_id' => $projectId])->orderBy('name', 'ASC')->orderBy('id', 'ASC')->fetchAll();
	}
}
