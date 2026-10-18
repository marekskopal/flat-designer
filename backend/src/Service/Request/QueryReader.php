<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Request;

use FlatDesigner\Service\Request\Exception\InvalidRequestException;
use Psr\Http\Message\ServerRequestInterface;

/** Typed access to query parameters (`?projectId=3`), the sibling of `BodyReader`. */
final readonly class QueryReader
{
	/** A whole number, 0 or more; null when absent (an empty value counts as absent). */
	public static function optionalInt(ServerRequestInterface $request, string $key): ?int
	{
		$value = $request->getQueryParams()[$key] ?? null;
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_string($value) || !ctype_digit($value)) {
			throw new InvalidRequestException(sprintf('Query parameter "%s" must be a whole number.', $key));
		}

		return (int) $value;
	}
}
