<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use DateTimeImmutable;
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

	/** The highest thread number on a floor (0 without threads); the next thread takes the one after it. */
	public function lastNumber(int $floorId): int
	{
		$last = $this->select()->where(['floor_id' => $floorId])->where(['number', '!=', null])->orderBy('number', 'DESC')->limit(
			1,
		)->fetchOne();

		return $last->number ?? 0;
	}

	public function countByShareLinkSince(int $shareLinkId, DateTimeImmutable $since): int
	{
		return $this->select()->where(['share_link_id' => $shareLinkId])->where(['created_at', '>=', $since])->count();
	}
}
