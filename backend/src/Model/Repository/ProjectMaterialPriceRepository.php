<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\ProjectMaterialPrice;

/** @extends ARepository<ProjectMaterialPrice> */
final class ProjectMaterialPriceRepository extends ARepository
{
	/** @return list<ProjectMaterialPrice> */
	public function findByProject(int $projectId): array
	{
		return $this->select()->where(['project_id' => $projectId])->orderBy('id', 'ASC')->fetchAll();
	}
}
