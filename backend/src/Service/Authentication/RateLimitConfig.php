<?php

declare(strict_types=1);

namespace Izbik\Service\Authentication;

final readonly class RateLimitConfig
{
	private const int DefaultLoginThreshold = 5;
	private const int DefaultBackoffCapSeconds = 3600;
	private const int DefaultPasswordResetsPerHour = 5;

	public function __construct(
		public int $loginThreshold,
		public int $loginBackoffCapSeconds,
		public int $passwordResetsPerHour = self::DefaultPasswordResetsPerHour,
	) {
	}

	public static function fromEnv(): self
	{
		$threshold = (int) getenv('RATE_LIMIT_LOGIN_ATTEMPTS');
		$cap = (int) getenv('RATE_LIMIT_LOGIN_BACKOFF_CAP_SECONDS');
		$resets = (int) getenv('RATE_LIMIT_PASSWORD_RESETS_PER_HOUR');

		return new self(
			loginThreshold: $threshold >= 1 ? $threshold : self::DefaultLoginThreshold,
			loginBackoffCapSeconds: $cap >= 60 ? $cap : self::DefaultBackoffCapSeconds,
			passwordResetsPerHour: $resets >= 1 ? $resets : self::DefaultPasswordResetsPerHour,
		);
	}
}
