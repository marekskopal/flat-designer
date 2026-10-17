<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\OAuthAuthorization;

/** @extends ARepository<OAuthAuthorization> */
final class OAuthAuthorizationRepository extends ARepository
{
	public function findByAuthorizationCodeHash(string $hash): ?OAuthAuthorization
	{
		return $this->findOne(['authorization_code_hash' => $hash]);
	}

	public function findByAccessTokenHash(string $hash): ?OAuthAuthorization
	{
		return $this->findOne(['access_token_hash' => $hash]);
	}

	public function findByRefreshTokenHash(string $hash): ?OAuthAuthorization
	{
		return $this->findOne(['refresh_token_hash' => $hash]);
	}

	/** @return list<OAuthAuthorization> newest first */
	public function findByUser(int $userId): array
	{
		return $this->select()->where(['user_id' => $userId])->orderBy('created_at', 'DESC')->fetchAll();
	}
}
