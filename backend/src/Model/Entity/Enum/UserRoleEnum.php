<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

enum UserRoleEnum: string
{
	case Admin = 'Admin';
	case User = 'User';
}
