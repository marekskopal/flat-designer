<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project\Exception;

/** Rejected project change; the code is the HTTP status (422 invalid input by default, 409 conflict). */
final class ProjectException extends \RuntimeException
{
	public function __construct(string $message, int $code = 422)
	{
		parent::__construct($message, $code);
	}
}
