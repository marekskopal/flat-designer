<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\OAuthClient;

/** @extends ARepository<OAuthClient> */
final class OAuthClientRepository extends ARepository
{
	public function findByClientId(string $clientId): ?OAuthClient
	{
		return $this->findOne(['client_id' => $clientId]);
	}
}
