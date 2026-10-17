<?php

declare(strict_types=1);

namespace FlatDesigner\Response;

final class ForbiddenResponse extends ErrorResponse
{
	/** @param array<non-empty-string, array<string>|string> $headers */
	public function __construct(string $data, int $status = 403, array $headers = [], int $encodingOptions = self::DEFAULT_JSON_FLAGS)
	{
		parent::__construct($data, $status, $headers, $encodingOptions);
	}
}
