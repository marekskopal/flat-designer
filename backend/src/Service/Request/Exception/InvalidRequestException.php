<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Request\Exception;

/** Malformed client input; the router's throwable handler turns the code into the response status. */
final class InvalidRequestException extends \RuntimeException
{
	public function __construct(string $message, int $code = 400, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}
