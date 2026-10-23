<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Comment;

use FlatDesigner\Model\Entity\Comment;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\CommentRepository;
use FlatDesigner\Service\Project\FloorMatcher;

/**
 * Carries the review comments over a version restore. They are not in snapshots: a restore replaces the floors (and
 * their comments go with them, ON DELETE CASCADE), so the comments are read before and recreated after it on the
 * restored floor `FloorMatcher` finds. Comments on a floor the version does not have are lost.
 *
 * A restored floor's thread numbering continues after the highest of the floors it stands for, so numbers of threads
 * deleted before the restore are not given again. Threads keep their numbers, except where two floors meet on one
 * (same level, and the version has one floor there): a number taken there already is replaced by the next free one.
 *
 * @phpstan-import-type FloorNumbering from CommentNumbering
 * @phpstan-type KeptComments array{
 *     floors: list<FloorNumbering>,
 *     comments: list<array{level: int, floorName: string, comment: Comment}>,
 * }
 */
final readonly class CommentKeeper
{
	public function __construct(private CommentRepository $commentRepository, private CommentNumbering $numbering)
	{
	}

	/** @return KeptComments the floors' numbering and the comments, threads before their replies */
	public function keep(Project $project): array
	{
		return [
			'floors' => $this->numbering->floorNumbering($project->id),
			'comments' => array_map(static fn (Comment $comment): array => [
				'level' => $comment->floor->level,
				'floorName' => $comment->floor->name,
				'comment' => $comment,
			], $this->commentRepository->findByProject($project->id)),
		];
	}

	/**
	 * @param KeptComments $kept from `keep()`
	 * @param list<Floor> $floors the restored floors
	 */
	public function restore(array $kept, array $floors): void
	{
		$numbers = new RestoredThreadNumbers($kept['floors'], $floors);

		/** @var array<int, Comment> $threads new threads by their old id */
		$threads = [];
		foreach ($kept['comments'] as ['level' => $level, 'floorName' => $floorName, 'comment' => $old]) {
			$parent = null;
			if ($old->parent !== null) {
				$parent = $threads[$old->parent->id] ?? null;
				if ($parent === null) {
					continue;
				}
				$floor = $parent->floor;
				$number = null;
			} else {
				$floor = FloorMatcher::like($floors, $level, $floorName);
				if ($floor === null) {
					continue;
				}
				$number = $numbers->number($floor, $old->number);
			}

			$comment = new Comment(
				project: $old->project,
				floor: $floor,
				parent: $parent,
				number: $number,
				x: $old->x,
				y: $old->y,
				z: $old->z,
				body: $old->body,
				authorType: $old->authorType,
				createdBy: $old->createdBy,
				mcpClientId: $old->mcpClientId,
				mcpClientName: $old->mcpClientName,
				guestName: $old->guestName,
				shareLink: $old->shareLink,
			);
			$comment->resolvedAt = $old->resolvedAt;
			$comment->createdAt = $old->createdAt;
			$comment->updatedAt = $old->updatedAt;
			$this->commentRepository->persist($comment);
			if ($parent === null) {
				$threads[$old->id] = $comment;
			}
		}

		$numbers->save($this->numbering);
	}
}
