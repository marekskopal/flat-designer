<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Material;

/** @extends ARepository<Material> */
final class MaterialRepository extends ARepository
{
	public function findById(int $materialId): ?Material
	{
		return $this->findOne(['id' => $materialId]);
	}

	/** @return list<Material> */
	public function findAllOrdered(): array
	{
		return $this->select()
			->orderBy('code', 'ASC')
			->fetchAll();
	}
}
