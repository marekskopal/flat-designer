<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** Who a furniture definition belongs to: everyone (managed by admins), one user's library, or one project. */
enum FurnitureScopeEnum: string
{
	case Global = 'global';
	case User = 'user';
	case Project = 'project';
}
