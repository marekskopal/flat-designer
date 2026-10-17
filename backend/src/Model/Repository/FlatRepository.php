<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\Flat;

/** @extends ARepository<Flat> */
final class FlatRepository extends ARepository
{
	public function findById(int $flatId): ?Flat
	{
		return $this->findOne(['id' => $flatId]);
	}

	/** @return list<Flat> */
	public function findAllOrdered(): array
	{
		return $this->select()
			->orderBy('name', 'ASC')
			->fetchAll();
	}
}
