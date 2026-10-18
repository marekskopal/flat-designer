<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use FlatDesigner\Service\Furniture\Expression\ExpressionErrorEnum;
use FlatDesigner\Service\Furniture\Expression\ExpressionException;
use RuntimeException;

/** A definition that cannot be evaluated; `path` points into the definition JSON (`parts[2].y`) or the values (`values.t`). */
final class DefinitionException extends RuntimeException
{
	public function __construct(
		public readonly DefinitionErrorEnum|ExpressionErrorEnum $error,
		public readonly string $path,
		string $message,
	) {
		parent::__construct(sprintf('%s: %s', $path, $message));
	}

	public static function invalid(string $path, string $message): self
	{
		return new self(DefinitionErrorEnum::Invalid, $path, $message);
	}

	public static function fromExpression(string $path, ExpressionException $exception): self
	{
		return new self($exception->error, $path, $exception->getMessage());
	}
}
