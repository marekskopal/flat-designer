<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller;

use FlatDesigner\Controller\CurrentUserController;
use FlatDesigner\Route\Routes;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CurrentUserController::class)]
final class CurrentUserControllerTest extends IntegrationTestCase
{
	public function testGetReturnsProfileWithoutPassword(): void
	{
		$user = Fixture::createUser('jane@example.com', 'Jane');

		$body = $this->jsonBody($this->request('GET', Routes::CurrentUser->value, authenticatedAs: $user));

		self::assertSame('Jane', $body['name']);
		self::assertSame('User', $body['role']);
		self::assertArrayNotHasKey('password', $body);
	}

	public function testPatchRenames(): void
	{
		$user = Fixture::createUser();

		$response = $this->request('PATCH', Routes::CurrentUser->value, ['name' => ' Renamed '], $user);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame('Renamed', $this->jsonBody($response)['name']);
		self::assertSame(422, $this->request('PATCH', Routes::CurrentUser->value, ['name' => ''], $user)->getStatusCode());
	}

	public function testPasswordChangeRevokesOldTokensAndReturnsNewOnes(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$oldToken = Fixture::accessTokenFor($user);

		$response = $this->request(
			'POST',
			Routes::CurrentUserPassword->value,
			['currentPassword' => Fixture::Password, 'newPassword' => 'Changed123'],
			bearerToken: $oldToken,
		);

		self::assertSame(200, $response->getStatusCode());
		$newToken = self::stringField($this->jsonBody($response)['accessToken']);
		self::assertSame(401, $this->request('GET', Routes::CurrentUser->value, bearerToken: $oldToken)->getStatusCode());
		self::assertSame(200, $this->request('GET', Routes::CurrentUser->value, bearerToken: $newToken)->getStatusCode());

		$login = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Changed123']);
		self::assertSame(200, $login->getStatusCode());
	}

	public function testPasswordChangeChecksCurrentPasswordAndStrength(): void
	{
		$user = Fixture::createUser();

		$wrongCurrent = $this->request(
			'POST',
			Routes::CurrentUserPassword->value,
			['currentPassword' => 'Wrong1234', 'newPassword' => 'Changed123'],
			$user,
		);
		$weak = $this->request(
			'POST',
			Routes::CurrentUserPassword->value,
			['currentPassword' => Fixture::Password, 'newPassword' => 'weak'],
			$user,
		);

		self::assertSame(422, $wrongCurrent->getStatusCode());
		self::assertSame(422, $weak->getStatusCode());
	}
}
