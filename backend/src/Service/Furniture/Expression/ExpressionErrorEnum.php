<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

/** Why an expression was rejected; the values are the codes of the shared fixture (tests/fixtures/furniture-expressions.json). */
enum ExpressionErrorEnum: string
{
	case Syntax = 'syntax';
	/** A null where a value is needed (anything but `??`, `== / != null` and `contains`). */
	case Null = 'null';
	/** A list index that is not a whole number within the list. */
	case Index = 'index';
	/** A record without the field. */
	case UnknownField = 'unknownField';
	case UnknownVariable = 'unknownVariable';
	case UnknownFunction = 'unknownFunction';
	case Arity = 'arity';
	case Type = 'type';
	case DivisionByZero = 'divisionByZero';
	case NonFinite = 'nonFinite';
	case TooLong = 'tooLong';
	case TooDeep = 'tooDeep';
}
