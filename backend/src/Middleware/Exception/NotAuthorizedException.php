<?php

declare(strict_types=1);

namespace FlatDesigner\Middleware\Exception;

final class NotAuthorizedException extends \RuntimeException
{
	public function __construct(string $message, ?\Throwable $previous = null)
	{
		parent::__construct($message, 401, $previous);
	}
}
