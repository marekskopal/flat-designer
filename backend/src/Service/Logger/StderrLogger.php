<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;
use const DATE_ATOM;
use const STDERR;

/** Writes one line per record to stderr, which FrankenPHP forwards to the container log. */
final class StderrLogger extends AbstractLogger
{
	private const array VerboseLevels = [LogLevel::INFO, LogLevel::DEBUG, LogLevel::NOTICE];

	public function __construct(private readonly bool $debug)
	{
	}

	/** @param array<mixed> $context */
	public function log(mixed $level, string|Stringable $message, array $context = []): void
	{
		$level = is_string($level) ? $level : LogLevel::ERROR;
		if (!$this->debug && in_array($level, self::VerboseLevels, true)) {
			return;
		}

		$line = sprintf('[%s] %s: %s', date(DATE_ATOM), strtoupper($level), (string) $message);

		$exception = $context['exception'] ?? null;
		if ($exception instanceof Throwable) {
			$line .= ' ' . $exception::class . ' in ' . $exception->getFile() . ':' . $exception->getLine();
		}

		fwrite(STDERR, $line . "\n");
	}
}
