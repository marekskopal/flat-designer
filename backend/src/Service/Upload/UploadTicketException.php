<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Upload;

use RuntimeException;

final class UploadTicketException extends RuntimeException
{
	public static function notFound(): self
	{
		return new self('The upload URL is unknown, expired or already used; ask for a new one.', 404);
	}

	public static function accessLost(): self
	{
		return new self('You no longer have access to the project this upload was requested for.', 403);
	}
}
