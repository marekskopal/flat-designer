<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use DateTimeImmutable;
use Izbik\Model\Entity\PasswordResetToken;

/** @extends ARepository<PasswordResetToken> */
final class PasswordResetTokenRepository extends ARepository
{
	public function findOneByTokenHash(string $tokenHash): ?PasswordResetToken
	{
		return $this->findOne(['token_hash' => $tokenHash]);
	}

	public function countByUserSince(int $userId, DateTimeImmutable $since): int
	{
		return $this->select()
			->where(['user_id' => $userId])
			->where(['created_at', '>=', $since->format('Y-m-d H:i:s')])
			->count();
	}

	/** @return list<PasswordResetToken> */
	public function findUnusedByUser(int $userId): array
	{
		return $this->select()
			->where(['user_id' => $userId])
			->where(['used_at' => null])
			->fetchAll();
	}
}
