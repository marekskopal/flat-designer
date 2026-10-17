<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Server;

use MarekSkopal\ORM\Exception\ORMException;
use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Exception\ExceptionInterface;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Result\CallToolResult;
use PDOException;
use RuntimeException;
use Throwable;
use const JSON_THROW_ON_ERROR;

/**
 * Turns domain errors raised by tools (`EditException` "The opening overlaps …", "Project 3 not found.")
 * into `ToolCallException`, which the SDK returns as an error result carrying the message, so the agent
 * can correct itself. Other throwables would collapse into a message-less internal error.
 *
 * Infrastructure faults stay internal errors (and are logged): they are nothing the agent did wrong.
 *
 * A DTO result is also turned into the tool result here: the SDK derives `structuredContent` from an object by
 * decoding its JSON into PHP arrays, which turns empty JSON objects (item `params`) into `[]`.
 */
final readonly class ToolErrorTranslatingReferenceHandler implements ReferenceHandlerInterface
{
	public function __construct(private ReferenceHandlerInterface $referenceHandler)
	{
	}

	/** @param array<string, mixed> $arguments */
	public function handle(ElementReference $reference, array $arguments): mixed
	{
		try {
			$result = $this->referenceHandler->handle($reference, $arguments);
		} catch (Throwable $exception) {
			if (
				!$exception instanceof RuntimeException
				|| $exception instanceof ExceptionInterface
				|| $exception instanceof ORMException
				|| $exception instanceof PDOException
			) {
				throw $exception;
			}

			throw new ToolCallException($exception->getMessage(), previous: $exception);
		}

		if (!$reference instanceof ToolReference || !is_object($result) || !str_starts_with($result::class, 'FlatDesigner\\Dto\\')) {
			return $result;
		}

		$structuredContent = json_decode(json_encode($result, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

		return new CallToolResult($reference->formatResult($result), structuredContent: $structuredContent);
	}
}
