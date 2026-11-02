<?php

declare(strict_types=1);

namespace Izbik\Service\Realtime;

use SensitiveParameter;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;

/** A short-lived publisher JWT for every publish call, allowed to publish to any topic. */
final readonly class MercurePublisherTokenProvider implements TokenProviderInterface
{
	private const string Subject = 'izbik-backend';
	private const int TtlSeconds = 60;

	public function __construct(#[SensitiveParameter] private string $key, private string $audience)
	{
	}

	public function getJwt(): string
	{
		return MercureAccessToken::encode(
			$this->key,
			$this->audience,
			self::Subject,
			MercureAccessToken::ActionPublish,
			['*'],
			self::TtlSeconds,
		);
	}
}
