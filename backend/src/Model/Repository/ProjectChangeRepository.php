<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\ProjectChange;
use MarekSkopal\ORM\Query\Select;

/** @extends ARepository<ProjectChange> */
final class ProjectChangeRepository extends ARepository
{
	public function findById(int $changeId): ?ProjectChange
	{
		return $this->findOne(['id' => $changeId]);
	}

	/** @return list<ProjectChange> Newest first. */
	public function findRecent(int $projectId, int $limit): array
	{
		return $this->select()->where(['project_id' => $projectId])->orderBy('id', 'DESC')->limit($limit)->fetchAll();
	}

	/** The actor's newest change that can be undone. */
	public function findUndoable(int $projectId, int $userId, ?string $mcpClientId): ?ProjectChange
	{
		return $this->actorChanges($projectId, $userId, $mcpClientId)
			->where(['undone_at' => null])
			->orderBy('id', 'DESC')
			->limit(1)
			->fetchOne();
	}

	/** The actor's most recently undone change that can be redone. */
	public function findRedoable(int $projectId, int $userId, ?string $mcpClientId): ?ProjectChange
	{
		return $this->actorChanges($projectId, $userId, $mcpClientId)
			->where(['undone_at', '!=', null])
			->orderBy('undone_at', 'DESC')
			->orderBy('id', 'ASC')
			->limit(1)
			->fetchOne();
	}

	/** @return list<ProjectChange> the actor's undone changes, whose redo a new change drops */
	public function findRedoables(int $projectId, int $userId, ?string $mcpClientId): array
	{
		return $this->actorChanges($projectId, $userId, $mcpClientId)->where(['undone_at', '!=', null])->fetchAll();
	}

	/** @return list<ProjectChange> the changes of one batch, in the order they were made */
	public function findBatch(int $projectId, string $batch): array
	{
		return $this->select()->where(['project_id' => $projectId, 'batch' => $batch])->orderBy('id', 'ASC')->fetchAll();
	}

	/** Ends undo and redo for the whole project, e.g. after its floors were replaced by a version. */
	public function closeAll(int $projectId): void
	{
		foreach ($this->select()->where(['project_id' => $projectId, 'closed' => false])->fetchAll() as $change) {
			$change->closed = true;
			$this->schedulePersist($change);
		}
		$this->flush();
	}

	/** @return Select<ProjectChange> */
	private function actorChanges(int $projectId, int $userId, ?string $mcpClientId): Select
	{
		return $this->select()->where([
			'project_id' => $projectId,
			'user_id' => $userId,
			'mcp_client_id' => $mcpClientId,
			'closed' => false,
		]);
	}
}
