<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use RuntimeException;
use Throwable;

/** Rejected change of a furniture definition; the code is the HTTP status (422 invalid, 403 not allowed, 404 not found). */
final class FurnitureException extends RuntimeException
{
	public function __construct(string $message, int $code = 422, ?Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}

	public static function forbidden(string $message): self
	{
		return new self($message, 403);
	}

	public static function notFound(string $message): self
	{
		return new self($message, 404);
	}
}
