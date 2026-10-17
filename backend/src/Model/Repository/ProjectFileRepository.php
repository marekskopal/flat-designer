<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\ProjectFile;

/** @extends ARepository<ProjectFile> */
final class ProjectFileRepository extends ARepository
{
	public function findOneInProject(int $projectId, int $fileId): ?ProjectFile
	{
		return $this->findOne(['id' => $fileId, 'project_id' => $projectId]);
	}

	/** @return list<ProjectFile> */
	public function findByProject(int $projectId): array
	{
		return $this->select()->where(['project_id' => $projectId])->orderBy('filename', 'ASC')->fetchAll();
	}
}
