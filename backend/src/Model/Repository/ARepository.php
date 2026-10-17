<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use MarekSkopal\ORM\Repository\AbstractRepository;

/**
 * Base repository exposing the ORM's shared unit of work for batched writes.
 *
 * `persist()` / `delete()` write immediately. The `schedule*()` methods only queue the entity; the
 * queued work is written by the next `flush()` — or by the next immediate `persist()` / `delete()`
 * on *any* repository, since every repository shares one unit of work. A flush writes the batch in
 * one transaction, with one multi-row INSERT per class and one `DELETE … IN` per class, ordered so
 * children are deleted before their parents.
 *
 * @template T of object
 * @extends AbstractRepository<T>
 */
abstract class ARepository extends AbstractRepository
{
	/** @param T $entity */
	public function schedulePersist(object $entity): void
	{
		$this->unitOfWork->persist($entity);
	}

	/** @param T $entity */
	public function scheduleDelete(object $entity): void
	{
		$this->unitOfWork->remove($entity);
	}

	/** Writes everything scheduled on the shared unit of work, from any repository. */
	public function flush(): void
	{
		$this->unitOfWork->flush();
	}
}
