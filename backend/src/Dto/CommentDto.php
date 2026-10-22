<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Comment;
use FlatDesigner\Model\Entity\Enum\CommentAuthorEnum;
use FlatDesigner\Service\Comment\CommentActor;
use const DATE_ATOM;

/**
 * A review comment: its floor, the point it is pinned to (plan mm, `z` above the floor), the thread it replies to
 * (`parentId`), the thread's `number` (on its pin; null on a reply) and its author. `canEdit` / `canDelete` are for the reader (always false for guests).
 */
final readonly class CommentDto
{
	public function __construct(
		public int $id,
		public int $floorId,
		public ?int $parentId,
		public ?int $number,
		public int $x,
		public int $y,
		public int $z,
		public string $body,
		public CommentAuthorEnum $authorType,
		public string $authorName,
		public bool $resolved,
		public string $createdAt,
		public string $updatedAt,
		public bool $canEdit,
		public bool $canDelete,
	) {
	}

	public static function fromEntity(Comment $comment, ?CommentActor $reader): self
	{
		return new self(
			id: $comment->id,
			floorId: $comment->floor->id,
			parentId: $comment->parent?->id,
			number: $comment->number,
			x: $comment->x,
			y: $comment->y,
			z: $comment->z,
			body: $comment->body,
			authorType: $comment->authorType,
			authorName: self::authorName($comment),
			resolved: $comment->resolvedAt !== null,
			createdAt: $comment->createdAt->format(DATE_ATOM),
			updatedAt: $comment->updatedAt->format(DATE_ATOM),
			canEdit: $reader?->wrote($comment) ?? false,
			canDelete: $reader !== null && $reader->type !== CommentAuthorEnum::Guest && $reader->mayDelete($comment),
		);
	}

	private static function authorName(Comment $comment): string
	{
		return match ($comment->authorType) {
			CommentAuthorEnum::User => $comment->createdBy->name ?? 'Former user',
			CommentAuthorEnum::Agent => $comment->mcpClientName ?? 'Agent',
			CommentAuthorEnum::Guest => $comment->guestName ?? 'Guest',
		};
	}
}
