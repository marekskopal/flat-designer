<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use Izbik\Model\Entity\OAuthClient;

/** @extends ARepository<OAuthClient> */
final class OAuthClientRepository extends ARepository
{
	public function findByClientId(string $clientId): ?OAuthClient
	{
		return $this->findOne(['client_id' => $clientId]);
	}
}
