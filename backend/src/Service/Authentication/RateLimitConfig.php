<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Authentication;

final readonly class RateLimitConfig
{
	private const int DefaultLoginThreshold = 5;
	private const int DefaultBackoffCapSeconds = 3600;

	public function __construct(public int $loginThreshold, public int $loginBackoffCapSeconds)
	{
	}

	public static function fromEnv(): self
	{
		$threshold = (int) getenv('RATE_LIMIT_LOGIN_ATTEMPTS');
		$cap = (int) getenv('RATE_LIMIT_LOGIN_BACKOFF_CAP_SECONDS');

		return new self(
			loginThreshold: $threshold >= 1 ? $threshold : self::DefaultLoginThreshold,
			loginBackoffCapSeconds: $cap >= 60 ? $cap : self::DefaultBackoffCapSeconds,
		);
	}
}
