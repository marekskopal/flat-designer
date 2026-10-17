<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Exception;

/**
 * Rejected edit; the code is the HTTP status: 404 element not found, 409 conflict (stale revision,
 * nothing to undo, changed since), 422 invalid value. MCP tools show the message to the agent.
 */
final class EditException extends \RuntimeException
{
	public function __construct(string $message, int $code = 422, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
	}

	public static function invalid(string $message): self
	{
		return new self($message, 422);
	}

	public static function conflict(string $message): self
	{
		return new self($message, 409);
	}

	public static function notFound(string $message): self
	{
		return new self($message, 404);
	}
}
