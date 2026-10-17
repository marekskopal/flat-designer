<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

enum ElementTypeEnum: string
{
	case Item = 'Item';
	case Wall = 'Wall';
	case Opening = 'Opening';
	case Room = 'Room';
	case Group = 'Group';
	case VariantSet = 'VariantSet';
}
