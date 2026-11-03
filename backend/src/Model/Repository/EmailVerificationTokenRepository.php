<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use DateTimeImmutable;
use Izbik\Model\Entity\EmailVerificationToken;

/** @extends ARepository<EmailVerificationToken> */
final class EmailVerificationTokenRepository extends ARepository
{
	public function findOneByTokenHash(string $tokenHash): ?EmailVerificationToken
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

	/** @return list<EmailVerificationToken> */
	public function findUnusedByUser(int $userId): array
	{
		return $this->select()
			->where(['user_id' => $userId])
			->where(['used_at' => null])
			->fetchAll();
	}
}
