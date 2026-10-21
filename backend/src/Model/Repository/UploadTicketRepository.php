<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Repository;

use FlatDesigner\Model\Entity\UploadTicket;

/** @extends ARepository<UploadTicket> */
final class UploadTicketRepository extends ARepository
{
	public function findOneByTokenHash(string $tokenHash): ?UploadTicket
	{
		return $this->findOne(['token_hash' => $tokenHash]);
	}
}
