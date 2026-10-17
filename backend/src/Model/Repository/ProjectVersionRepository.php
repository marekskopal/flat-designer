<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\ProjectVersion;

/** @extends ARepository<ProjectVersion> */
final class ProjectVersionRepository extends ARepository
{
	public function findOneInProject(int $projectId, int $versionId): ?ProjectVersion
	{
		return $this->findOne(['id' => $versionId, 'project_id' => $projectId]);
	}

	/** @return list<ProjectVersion> Newest first. */
	public function findByProject(int $projectId): array
	{
		return $this->select()->where(['project_id' => $projectId])->orderBy('number', 'DESC')->fetchAll();
	}

	public function findLatest(int $projectId): ?ProjectVersion
	{
		return $this->select()->where(['project_id' => $projectId])->orderBy('number', 'DESC')->limit(1)->fetchOne();
	}
}
