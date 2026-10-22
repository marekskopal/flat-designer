<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** Who wrote a review comment: a signed-in user in the app, an agent over MCP, or a guest of a share link. */
enum CommentAuthorEnum: string
{
	case User = 'User';
	case Agent = 'Agent';
	case Guest = 'Guest';
}
