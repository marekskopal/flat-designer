<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Comment;

use DateTimeImmutable;
use FlatDesigner\Dto\CommentDto;
use FlatDesigner\Dto\CommentInputDto;
use FlatDesigner\Dto\CommentUpdateDto;
use FlatDesigner\Model\Entity\Comment;
use FlatDesigner\Model\Entity\Enum\CommentAuthorEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\CommentRepository;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Realtime\ProjectUpdatePublisher;
use MarekSkopal\ORM\ORM;

/**
 * The review comments of a project: threads pinned to a point of a floor, with one level of replies. They are not floor
 * content (no revision, no undo, not in snapshots); a change sends `commentsChanged`. Share link guests go through
 * `SharedComments`.
 */
final readonly class CommentProvider
{
	public const int MaxBodyLength = 2000;

	/** How far from the plan origin a pin may be, mm. */
	private const int MaxPlanReach = 1000000;
	private const int MinZ = -10000;
	private const int MaxZ = 100000;

	public function __construct(
		private CommentRepository $commentRepository,
		private FloorRepository $floorRepository,
		private CommentNumbering $numbering,
		private ProjectUpdatePublisher $publisher,
		private ORM $orm,
	) {
	}

	/**
	 * Threads oldest first, each followed by its replies.
	 *
	 * @param bool $includeResolved also the resolved threads (with their replies)
	 * @return list<Comment>
	 */
	public function list(Project $project, ?int $floorId = null, bool $includeResolved = true): array
	{
		$comments = $this->commentRepository->findByProject($project->id, $floorId);
		$threads = [];
		$replies = [];
		foreach ($comments as $comment) {
			if ($comment->parent === null) {
				if ($includeResolved || $comment->resolvedAt === null) {
					$threads[] = $comment;
				}
			} else {
				$replies[$comment->parent->id][] = $comment;
			}
		}

		$ordered = [];
		foreach ($threads as $thread) {
			$ordered[] = $thread;
			array_push($ordered, ...$replies[$thread->id] ?? []);
		}

		return $ordered;
	}

	public function find(Project $project, int $commentId): ?Comment
	{
		return $this->commentRepository->findOneInProject($project->id, $commentId);
	}

	public function create(CommentActor $actor, Project $project, CommentInputDto $input): Comment
	{
		$floorId = $input->floorId ?? throw new ProjectException('A comment needs its floor (floorId).', 422);
		$floor = $this->floorRepository->findOneInProject($project->id, $floorId)
			?? throw new ProjectException(sprintf('Floor %d is not a floor of this project.', $floorId), 422);

		return $this->orm->getTransactionProvider()->transaction(fn (): Comment => $this->add($actor, $project, $floor, $input));
	}

	/** Changes the text and/or whether the thread is resolved; nothing is written (or published) when neither changes. */
	public function update(CommentActor $actor, Comment $comment, CommentUpdateDto $input): Comment
	{
		$changed = false;
		if ($input->body !== null) {
			if (!$actor->wrote($comment)) {
				throw new ProjectException('Only the author may edit a comment.', 403);
			}
			$body = self::body($input->body);
			$changed = $body !== $comment->body;
			$comment->body = $body;
		}
		if ($input->resolved !== null) {
			if ($comment->parent !== null) {
				throw new ProjectException('Resolve the thread, not a reply.', 422);
			}
			if ($input->resolved !== ($comment->resolvedAt !== null)) {
				$comment->resolvedAt = $input->resolved ? new DateTimeImmutable() : null;
				$changed = true;
			}
		}
		if (!$changed) {
			return $comment;
		}
		$comment->updatedAt = new DateTimeImmutable();
		$this->commentRepository->persist($comment);
		$this->publisher->markCommentsChanged($comment->project->id);

		return $comment;
	}

	/** Deletes a comment; a thread goes with its replies. */
	public function delete(CommentActor $actor, Comment $comment): void
	{
		if (!$actor->mayDelete($comment)) {
			throw new ProjectException('Only the author may delete this comment.', 403);
		}
		$this->commentRepository->delete($comment);
		$this->publisher->markCommentsChanged($comment->project->id);
	}

	public function toDto(Comment $comment, ?CommentActor $reader): CommentDto
	{
		return CommentDto::fromEntity($comment, $reader);
	}

	/**
	 * A thread or a reply on a floor of the project, which the caller has checked. The caller runs this in a transaction,
	 * which keeps the floor's thread numbering locked (`CommentNumbering::next()`).
	 */
	public function add(CommentActor $actor, Project $project, Floor $floor, CommentInputDto $input): Comment
	{
		$parent = null;
		if ($input->parentId !== null) {
			$parent = $this->commentRepository->findOneInProject($project->id, $input->parentId);
			if ($parent === null || $parent->floor->id !== $floor->id) {
				throw new ProjectException(sprintf('Comment %d is not a comment of this floor.', $input->parentId), 422);
			}
			if ($parent->parent !== null) {
				throw new ProjectException('Reply to the thread, not to a reply.', 422);
			}
			if ($actor->type === CommentAuthorEnum::Guest && $parent->resolvedAt !== null) {
				throw new ProjectException('This thread is resolved.', 422);
			}
			[$x, $y, $z] = [$parent->x, $parent->y, $parent->z];
		} else {
			if ($input->x === null || $input->y === null || $input->z === null) {
				throw new ProjectException('A new thread needs its point (x, y, z).', 422);
			}
			[$x, $y, $z] = self::point($input->x, $input->y, $input->z);
		}

		$now = new DateTimeImmutable();
		$comment = new Comment(
			project: $project,
			floor: $floor,
			parent: $parent,
			number: $parent === null ? $this->numbering->next($floor) : null,
			x: $x,
			y: $y,
			z: $z,
			body: self::body($input->body),
			authorType: $actor->type,
			createdBy: $actor->user,
			mcpClientId: $actor->mcpClientId,
			mcpClientName: $actor->mcpClientName,
			guestName: $actor->guestName,
			shareLink: $actor->shareLink,
		);
		$comment->createdAt = $now;
		$comment->updatedAt = $now;
		$this->commentRepository->persist($comment);
		$this->publisher->markCommentsChanged($project->id);

		return $comment;
	}

	private static function body(string $body): string
	{
		$body = trim($body);
		if ($body === '' || mb_strlen($body) > self::MaxBodyLength) {
			throw new ProjectException(sprintf('A comment needs a text of at most %d characters.', self::MaxBodyLength), 422);
		}

		return $body;
	}

	/** @return array{int, int, int} */
	private static function point(int $x, int $y, int $z): array
	{
		if (abs($x) > self::MaxPlanReach || abs($y) > self::MaxPlanReach || $z < self::MinZ || $z > self::MaxZ) {
			throw new ProjectException('The comment\'s point is out of range.', 422);
		}

		return [$x, $y, $z];
	}
}
