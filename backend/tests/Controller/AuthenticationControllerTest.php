<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\AuthenticationController;
use FlatDesigner\Middleware\AuthorizationMiddleware;
use FlatDesigner\Model\Repository\UserRepository;
use FlatDesigner\Route\Routes;
use FlatDesigner\Tests\Support\AppHarness;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AuthenticationController::class)]
#[CoversClass(AuthorizationMiddleware::class)]
final class AuthenticationControllerTest extends IntegrationTestCase
{
	public function testLoginReturnsTokensThatAuthorizeRequests(): void
	{
		$user = Fixture::createUser('jane@example.com');

		$response = $this->request(
			'POST',
			Routes::AuthenticationLogin->value,
			['email' => ' Jane@Example.com', 'password' => Fixture::Password],
		);

		self::assertSame(200, $response->getStatusCode());
		$body = $this->jsonBody($response);
		self::assertSame($user->id, $body['userId']);

		$me = $this->request('GET', Routes::CurrentUser->value, bearerToken: self::stringField($body['accessToken']));
		self::assertSame(200, $me->getStatusCode());
		self::assertSame('jane@example.com', $this->jsonBody($me)['email']);
	}

	public function testLoginRejectsWrongPasswordAndUnknownEmail(): void
	{
		Fixture::createUser('jane@example.com');

		$wrongPassword = $this->request(
			'POST',
			Routes::AuthenticationLogin->value,
			['email' => 'jane@example.com', 'password' => 'Nope12345'],
		);
		$unknownEmail = $this->request(
			'POST',
			Routes::AuthenticationLogin->value,
			['email' => 'joe@example.com', 'password' => Fixture::Password],
		);

		self::assertSame(401, $wrongPassword->getStatusCode());
		self::assertSame(401, $unknownEmail->getStatusCode());
	}

	public function testRepeatedFailuresLockTheAccount(): void
	{
		Fixture::createUser('jane@example.com');

		for ($i = 0; $i < 4; $i++) {
			$this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Wrong1234']);
		}
		$fifth = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Wrong1234']);
		$correct = $this->request(
			'POST',
			Routes::AuthenticationLogin->value,
			['email' => 'jane@example.com', 'password' => Fixture::Password],
		);

		self::assertSame(429, $fifth->getStatusCode());
		self::assertSame('60', $fifth->getHeaderLine('Retry-After'));
		self::assertSame(429, $correct->getStatusCode());
	}

	public function testLoginRejectsMalformedBody(): void
	{
		$response = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com']);

		self::assertSame(400, $response->getStatusCode());
	}

	public function testSignUpCreatesUserWithUserRole(): void
	{
		$response = $this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'New@Example.com', 'password' => 'Secret123', 'name' => ' New User '],
		);

		self::assertSame(200, $response->getStatusCode());
		$user = $this->userRepository()->findByEmail('new@example.com');
		self::assertNotNull($user);
		self::assertSame('New User', $user->name);
		self::assertFalse($user->isAdmin());
		self::assertTrue(password_verify('Secret123', $user->password));
	}

	public function testSignUpWithExistingEmailAnswersTheSameAndKeepsTheAccount(): void
	{
		$existing = Fixture::createUser('jane@example.com', 'Jane');

		$response = $this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'jane@example.com', 'password' => 'Other1234', 'name' => 'Impostor'],
		);

		self::assertSame(200, $response->getStatusCode());
		$user = $this->userRepository()->findById($existing->id);
		self::assertNotNull($user);
		self::assertSame('Jane', $user->name);
		self::assertTrue(password_verify(Fixture::Password, $user->password));
	}

	public function testSignUpValidatesInput(): void
	{
		$weakPassword = $this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'a@example.com', 'password' => 'short', 'name' => 'A'],
		);
		$badEmail = $this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'nope', 'password' => 'Secret123', 'name' => 'A'],
		);
		$noName = $this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'a@example.com', 'password' => 'Secret123', 'name' => ' '],
		);

		self::assertSame(422, $weakPassword->getStatusCode());
		self::assertSame(422, $badEmail->getStatusCode());
		self::assertSame(422, $noName->getStatusCode());
	}

	public function testRefreshTokenIssuesNewPair(): void
	{
		$user = Fixture::createUser();

		$response = $this->request('POST', Routes::AuthenticationRefreshToken->value, ['refreshToken' => Fixture::refreshTokenFor($user)]);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame($user->id, $this->jsonBody($response)['userId']);
	}

	public function testAccessTokenIsNotAcceptedAsRefreshTokenAndViceVersa(): void
	{
		$user = Fixture::createUser();

		$refresh = $this->request('POST', Routes::AuthenticationRefreshToken->value, ['refreshToken' => Fixture::accessTokenFor($user)]);
		$access = $this->request('GET', Routes::CurrentUser->value, bearerToken: Fixture::refreshTokenFor($user));

		self::assertSame(401, $refresh->getStatusCode());
		self::assertSame(401, $access->getStatusCode());
	}

	public function testTokensAreRevokedWhenTokenVersionChanges(): void
	{
		$user = Fixture::createUser();
		$accessToken = Fixture::accessTokenFor($user);
		$refreshToken = Fixture::refreshTokenFor($user);

		$user->tokenVersion++;
		$this->userRepository()->persist($user);

		self::assertSame(401, $this->request('GET', Routes::CurrentUser->value, bearerToken: $accessToken)->getStatusCode());
		self::assertSame(
			401,
			$this->request('POST', Routes::AuthenticationRefreshToken->value, ['refreshToken' => $refreshToken])->getStatusCode(),
		);
	}

	public function testProtectedRoutesRequireToken(): void
	{
		self::assertSame(401, $this->request('GET', Routes::CurrentUser->value)->getStatusCode());
		self::assertSame(401, $this->request('GET', Routes::Flats->value)->getStatusCode());
		self::assertSame(401, $this->request('GET', Routes::CurrentUser->value, bearerToken: 'garbage')->getStatusCode());
	}

	private function userRepository(): UserRepository
	{
		$repository = AppHarness::container()->get(UserRepository::class);
		assert($repository instanceof UserRepository);

		return $repository;
	}
}
