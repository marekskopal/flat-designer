<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

use RuntimeException;
use Throwable;

/** A project file that cannot be imported; the code is the HTTP status (422, or 413 for one too large). */
final class ProjectImportException extends RuntimeException
{
	public function __construct(string $message, int $code = 422, ?Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}
