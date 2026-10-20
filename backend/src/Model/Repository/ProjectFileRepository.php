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

	/**
	 * Which of the ids are files of the project, ascending.
	 *
	 * @param list<int> $fileIds
	 * @return list<int>
	 */
	public function findIdsInProject(int $projectId, array $fileIds): array
	{
		if ($fileIds === []) {
			return [];
		}
		$files = $this->select()->where(['project_id' => $projectId])->where(['id', 'IN', $fileIds])->orderBy('id', 'ASC')->fetchAll();

		return array_map(static fn (ProjectFile $file): int => $file->id, $files);
	}

	/** @return list<ProjectFile> */
	public function findByProject(int $projectId): array
	{
		return $this->select()->where(['project_id' => $projectId])->orderBy('filename', 'ASC')->fetchAll();
	}
}
