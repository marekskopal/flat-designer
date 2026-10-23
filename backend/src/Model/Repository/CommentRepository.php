<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Comment;

/** @extends ARepository<Comment> */
final class CommentRepository extends ARepository
{
	public function findOneInProject(int $projectId, int $commentId): ?Comment
	{
		return $this->findOne(['id' => $commentId, 'project_id' => $projectId]);
	}

	/** @return list<Comment> oldest first */
	public function findByProject(int $projectId, ?int $floorId = null): array
	{
		$select = $this->select()->where(['project_id' => $projectId]);
		if ($floorId !== null) {
			$select->where(['floor_id' => $floorId]);
		}

		return $select->orderBy('id', 'ASC')->fetchAll();
	}
}
