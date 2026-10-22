<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** What a share link's guests may do with the review comments of its floor. */
enum ShareCommentsEnum: string
{
	case Hidden = 'hidden';
	case Read = 'read';
	case Write = 'write';
}
