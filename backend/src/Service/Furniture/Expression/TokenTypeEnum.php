<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

enum TokenTypeEnum
{
	case Number;
	case String;
	case Identifier;
	case Operator;
	case End;
}
