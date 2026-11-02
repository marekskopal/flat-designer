<?php

declare(strict_types=1);

namespace Izbik\Model\Repository;

use Izbik\Model\Entity\ShareLink;

/** @extends ARepository<ShareLink> */
final class ShareLinkRepository extends ARepository
{
	public function findOneInProject(int $projectId, int $shareLinkId): ?ShareLink
	{
		return $this->findOne(['id' => $shareLinkId, 'project_id' => $projectId]);
	}

	public function findOneByToken(string $token): ?ShareLink
	{
		return $this->findOne(['token' => $token]);
	}

	/** @return list<ShareLink> Newest first. */
	public function findByProject(int $projectId): array
	{
		return $this->select()->where(['project_id' => $projectId])->orderBy('id', 'DESC')->fetchAll();
	}
}
