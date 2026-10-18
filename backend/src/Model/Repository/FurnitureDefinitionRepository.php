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

	/** A not deleted definition of the project with exactly this name and JSON (a copy made by an earlier restore). */
	public function findProjectCopy(int $projectId, string $name, string $definition): ?FurnitureDefinition
	{
		return $this->findOne(['project_id' => $projectId, 'name' => $name, 'definition' => $definition, 'deleted_at' => null]);
	}

	/**
	 * Not deleted, by name: the global ones, the libraries of `$userIds` and the definitions of the project.
	 *
	 * @param list<int> $userIds
	 * @return list<FurnitureDefinition>
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
