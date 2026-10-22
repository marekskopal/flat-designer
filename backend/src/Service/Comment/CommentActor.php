<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Comment;

use FlatDesigner\Model\Entity\Comment;
use FlatDesigner\Model\Entity\Enum\CommentAuthorEnum;
use FlatDesigner\Model\Entity\ShareLink;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Actor\ActorContext;

/** Who reads or writes comments: a user in the app, an agent over MCP (its user plus the client) or a share link's guest. */
final readonly class CommentActor
{
	private function __construct(
		public CommentAuthorEnum $type,
		public ?User $user,
		public ?string $mcpClientId = null,
		public ?string $mcpClientName = null,
		public ?string $guestName = null,
		public ?ShareLink $shareLink = null,
	) {
	}

	/** The signed-in user, as a person or as the agent of the request's `ActorContext`. */
	public static function of(User $user, ActorContext $actorContext): self
	{
		$clientId = $actorContext->getMcpClientId();

		return $clientId === null
			? new self(CommentAuthorEnum::User, $user)
			: new self(CommentAuthorEnum::Agent, $user, $clientId, $actorContext->getMcpClientName());
	}

	public static function guest(string $name, ShareLink $link): self
	{
		return new self(CommentAuthorEnum::Guest, null, guestName: $name, shareLink: $link);
	}

	/** Only the author edits the text; guests never (they cannot be recognised again). */
	public function wrote(Comment $comment): bool
	{
		return $this->type !== CommentAuthorEnum::Guest
			&& $comment->authorType === $this->type
			&& $comment->createdBy !== null
			&& $comment->createdBy->id === $this->user?->id
			&& $comment->mcpClientId === $this->mcpClientId;
	}

	/** A person with access to the project deletes any comment (moderation); an agent only its own. */
	public function mayDelete(Comment $comment): bool
	{
		return $this->type === CommentAuthorEnum::User || $this->wrote($comment);
	}
}
