<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use Izbik\Model\Entity\Enum\UserRoleEnum;
use Izbik\Model\Entity\User;

/** @extends ARepository<User> */
final class UserRepository extends ARepository
{
	public function findById(int $userId): ?User
	{
		return $this->findOne(['id' => $userId]);
	}

	public function findByEmail(string $email): ?User
	{
		return $this->findOne(['email' => $email]);
	}

	/** @return list<User> */
	public function findAllOrdered(): array
	{
		return $this->select()->orderBy('id', 'ASC')->fetchAll();
	}

	public function countAdmins(): int
	{
		return $this->select()->where(['role' => UserRoleEnum::Admin->value])->count();
	}
}
