<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Authentication;

use FlatDesigner\Dto\AuthenticationDto;
use FlatDesigner\Dto\CredentialsDto;
use FlatDesigner\Service\Authentication\Exception\AuthenticationException;
use FlatDesigner\Service\Provider\UserProvider;

final readonly class AuthenticationService
{
	/**
	 * Bcrypt hash of a random throwaway string. Verified against when the account does not exist, so
	 * absent and present users take comparable time (no timing oracle for registered emails).
	 */
	private const string DummyPasswordHash = '$2y$12$bM7D3SRpgIxPKSOrodU8fufxpIw6wuJjlnOyH66eJdDqlexKtgeMa';

	public function __construct(
		private UserProvider $userProvider,
		private LoginAttemptService $loginAttempts,
		private TokenService $tokenService,
	)
	{
	}

	public function authenticate(CredentialsDto $credentials): AuthenticationDto
	{
		$user = $this->userProvider->getUserByEmail($credentials->email);
		if ($user === null) {
			password_verify($credentials->password, self::DummyPasswordHash);

			throw new AuthenticationException('Invalid credentials.');
		}

		$this->loginAttempts->assertNotLocked($user);

		if (!password_verify($credentials->password, $user->password)) {
			$this->loginAttempts->recordFailure($user);
			// If this failure just tripped the lock, surface that to the caller as 429.
			$this->loginAttempts->assertNotLocked($user);

			throw new AuthenticationException('Invalid credentials.');
		}

		$this->loginAttempts->recordSuccess($user);
		$this->userProvider->recordLogin($user);

		return $this->tokenService->createAuthentication($user);
	}
}
