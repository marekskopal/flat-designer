<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use Izbik\Model\Entity\ModelAsset;
use MarekSkopal\ORM\Query\Where\WhereBuilder;

/** @extends ARepository<ModelAsset> */
final class ModelAssetRepository extends ARepository
{
	/** Also a deleted one (items may still use it). */
	public function findById(int $modelId): ?ModelAsset
	{
		return $this->findOne(['id' => $modelId]);
	}

	/** A not deleted model of this source in the scope: global (no project) or the project's own. */
	public function findBySource(string $sourceUrl, ?int $projectId): ?ModelAsset
	{
		return $this->findOne(
			$projectId === null
				? ['source_url' => $sourceUrl, 'user_id' => null, 'project_id' => null, 'deleted_at' => null]
				: ['source_url' => $sourceUrl, 'project_id' => $projectId, 'deleted_at' => null],
		);
	}

	/**
	 * The stored files of the project's models (deleted ones too), to remove after the project rows.
	 *
	 * @return list<string>
	 */
	public function storageKeysOfProject(int $projectId): array
	{
		return array_map(
			static fn (ModelAsset $m): string => $m->storageKey,
			$this->select()->where(['project_id' => $projectId])->fetchAll(),
		);
	}

	/**
	 * The stored files of the user's library (deleted ones too), to remove after the user row.
	 *
	 * @return list<string>
	 */
	public function storageKeysOfUser(int $userId): array
	{
		return array_map(static fn (ModelAsset $m): string => $m->storageKey, $this->select()->where(['user_id' => $userId])->fetchAll());
	}

	/**
	 * Not deleted, by name: the global ones, the libraries of `$userIds` and the models of the project.
	 *
	 * @param list<int> $userIds
	 * @return list<ModelAsset>
	 */
	public function findVisible(array $userIds, ?int $projectId): array
	{
		return $this->select()
			->where(['deleted_at' => null])
			->where(static function (WhereBuilder $builder) use ($userIds, $projectId): void {
				$builder->where(['user_id' => null, 'project_id' => null]);
				if ($userIds !== []) {
					$builder->orWhere(['user_id', 'IN', $userIds]);
				}
				if ($projectId !== null) {
					$builder->orWhere(['project_id' => $projectId]);
				}
			})
			->orderBy('name')
			->orderBy('id')
			->fetchAll();
	}
}
