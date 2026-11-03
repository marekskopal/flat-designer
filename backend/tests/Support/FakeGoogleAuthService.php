<?php

declare(strict_types=1);

namespace Izbik\Tests\Support;

use Izbik\Service\Authentication\Exception\GoogleAuthException;
use Izbik\Service\Authentication\GoogleAuthServiceInterface;
use Izbik\Service\Authentication\GoogleIdentity;
use SensitiveParameter;

/** Stands in for Google's token check in tests: a token is valid when `identities` names it. */
final class FakeGoogleAuthService implements GoogleAuthServiceInterface
{
	public const string ClientId = 'test-client.apps.googleusercontent.com';

	/** @var array<string, GoogleIdentity> */
	public array $identities = [];

	public function clientId(): string
	{
		return self::ClientId;
	}

	public function verifyIdToken(#[SensitiveParameter] string $idToken): GoogleIdentity
	{
		return $this->identities[$idToken] ?? throw new GoogleAuthException('Unknown test token.');
	}
}
