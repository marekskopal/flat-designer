<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/** View modes of the viewer a share link may offer (values are the client's view names). */
enum ShareViewEnum: string
{
	case Overview = 'overview';
	case Top = 'top';
	case Walkthrough = 'walkthrough';
}
