<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

enum ChangeOperationEnum: string
{
	case Create = 'Create';
	case Update = 'Update';
	case Delete = 'Delete';
}
