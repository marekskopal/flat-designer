<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture\Expression;

enum TokenTypeEnum
{
	case Number;
	case String;
	case Identifier;
	case Operator;
	case End;
}
