<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Admin\Exception;

/** Rejected admin change; the code is the HTTP status (409 conflict by default, 422 invalid input). */
final class AdminUserException extends \RuntimeException
{
	public function __construct(string $message, int $code = 409)
	{
		parent::__construct($message, $code);
	}
}
