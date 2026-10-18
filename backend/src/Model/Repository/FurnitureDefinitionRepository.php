<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\FurnitureDefinition;
use MarekSkopal\ORM\Query\Where\WhereBuilder;

/** @extends ARepository<FurnitureDefinition> */
final class FurnitureDefinitionRepository extends ARepository
{
	/** Also a deleted one (items may still use it). */
	public function findById(int $definitionId): ?FurnitureDefinition
	{
		return $this->findOne(['id' => $definitionId]);
	}

	/**
	 * Not deleted, by name: the global ones, the libraries of `$userIds` and the definitions of the project.
	 *
	 * @param non-empty-list<int> $userIds
	 * @return list<FurnitureDefinition>
	 */
	public function findVisible(array $userIds, ?int $projectId): array
	{
		return $this->select()
			->where(['deleted_at' => null])
			->where(static function (WhereBuilder $builder) use ($userIds, $projectId): void {
				$builder->where(['user_id' => null, 'project_id' => null]);
				$builder->orWhere(['user_id', 'IN', $userIds]);
				if ($projectId !== null) {
					$builder->orWhere(['project_id' => $projectId]);
				}
			})
			->orderBy('name')
			->orderBy('id')
			->fetchAll();
	}
}
