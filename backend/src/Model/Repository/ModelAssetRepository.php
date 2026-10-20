<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\ModelAsset;
use MarekSkopal\ORM\Query\Where\WhereBuilder;

/** @extends ARepository<ModelAsset> */
final class ModelAssetRepository extends ARepository
{
	/** Also a deleted one (items may still use it). */
	public function findById(int $modelId): ?ModelAsset
	{
		return $this->findOne(['id' => $modelId]);
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
