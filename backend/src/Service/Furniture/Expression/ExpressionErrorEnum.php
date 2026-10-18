<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

/** Why an expression was rejected; the values are the codes of the shared fixture (tests/fixtures/furniture-expressions.json). */
enum ExpressionErrorEnum: string
{
	case Syntax = 'syntax';
	case UnknownVariable = 'unknownVariable';
	case UnknownFunction = 'unknownFunction';
	case Arity = 'arity';
	case Type = 'type';
	case DivisionByZero = 'divisionByZero';
	case NonFinite = 'nonFinite';
	case TooLong = 'tooLong';
	case TooDeep = 'tooDeep';
}
