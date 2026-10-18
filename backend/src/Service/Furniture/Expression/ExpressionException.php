<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\Expression;

use RuntimeException;

final class ExpressionException extends RuntimeException
{
	public function __construct(public readonly ExpressionErrorEnum $error, string $message)
	{
		parent::__construct($message);
	}
}
