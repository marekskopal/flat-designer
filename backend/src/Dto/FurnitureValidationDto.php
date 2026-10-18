<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Furniture\DefinitionException;
use FlatDesigner\Service\Furniture\ResolvedPart;

/**
 * Result of a furniture definition check: either `valid` with the parts at the default size and parameter values,
 * or the first `error` (`code`, `path` into the definition, `message`).
 */
final readonly class FurnitureValidationDto
{
	/**
	 * @param array{code: string, path: string, message: string}|null $error
	 * @param list<ResolvedPart> $parts
	 */
	public function __construct(public bool $valid, public ?array $error, public array $parts)
	{
	}

	/** @param list<ResolvedPart> $parts */
	public static function valid(array $parts): self
	{
		return new self(valid: true, error: null, parts: $parts);
	}

	public static function invalid(DefinitionException $exception): self
	{
		return new self(
			valid: false,
			error: ['code' => $exception->error->value, 'path' => $exception->path, 'message' => $exception->getMessage()],
			parts: [],
		);
	}
}
