<?php

declare(strict_types=1);

namespace FlatDesigner\Service\ModelAsset;

use Throwable;

/** Rejected model; the code is the HTTP status (422 invalid input by default, 403 not allowed, 404 not found). */
final class ModelAssetException extends \RuntimeException
{
	public function __construct(string $message, int $code = 422, ?Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}
}
