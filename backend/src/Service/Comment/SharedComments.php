<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Comment;

use DateTimeImmutable;
use FlatDesigner\Dto\CommentInputDto;
use FlatDesigner\Model\Entity\Comment;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\ShareLink;
use FlatDesigner\Model\Repository\CommentRepository;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;

/**
 * The review comments a share link's guests see and write: the open threads of the link's floor (in the working copy,
 * not the link's frozen version). Whether a link shows or takes them (`ShareSettingsDto::$comments`) is the caller's check.
 */
final readonly class SharedComments
{
	public const int MaxGuestNameLength = 100;

	/** Comments a share link's guests may write per hour, all together. */
	public const int GuestCommentsPerHour = 30;

	public function __construct(
		private CommentProvider $commentProvider,
		private CommentRepository $commentRepository,
		private FloorRepository $floorRepository,
	) {
	}

	/**
	 * The working copy's floor a share link shows: the floor of the same id (floors keep their ids in snapshots), or after
	 * a version restore gave the floors new ids, the one of the same name.
	 */
	public function floor(ShareLink $link): ?Floor
	{
		$floor = $this->floorRepository->findOneInProject($link->project->id, $link->floorId);
		if ($floor !== null) {
			return $floor;
		}
		foreach ($this->floorRepository->findByProject($link->project->id) as $candidate) {
			if ($candidate->name === $link->floorName) {
				return $candidate;
			}
		}

		return null;
	}

	/** @return list<Comment> the open threads of the link's floor with their replies */
	public function list(ShareLink $link): array
	{
		$floor = $this->floor($link);

		return $floor === null ? [] : $this->commentProvider->list($link->project, $floor->id, includeResolved: false);
	}

	/** A guest's thread or reply on the link's floor (`guestName` required, `floorId` ignored). */
	public function add(ShareLink $link, CommentInputDto $input): Comment
	{
		$name = trim($input->guestName ?? '');
		if ($name === '' || mb_strlen($name) > self::MaxGuestNameLength) {
			throw new ProjectException(sprintf('Your name is required (at most %d characters).', self::MaxGuestNameLength), 422);
		}
		if ($this->commentRepository->countByShareLinkSince($link->id, new DateTimeImmutable('-1 hour')) >= self::GuestCommentsPerHour) {
			throw new ProjectException('Too many comments through this link. Try again later.', 429);
		}
		$floor = $this->floor($link) ?? throw new ProjectException('The shared floor no longer exists.', 404);

		return $this->commentProvider->add(CommentActor::guest($name, $link), $link->project, $floor, $input);
	}
}
