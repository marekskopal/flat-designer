<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

enum UserRoleEnum: string
{
	case Admin = 'Admin';
	case User = 'User';
}
