<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Controller\AuthenticationController;
use Izbik\Model\Repository\UserRepository;
use Izbik\Route\Routes;
use Izbik\Service\Authentication\GoogleIdentity;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AuthenticationController::class)]
final class GoogleLoginTest extends IntegrationTestCase
{
	public function testFirstSignInCreatesAnAccountWithoutPassword(): void
	{
		AppHarness::googleAuth()->identities['token-a'] = new GoogleIdentity('google-1', 'Jane@Example.com', 'Jane Doe');

		$response = $this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-a']);

		self::assertSame(200, $response->getStatusCode());
		$me = $this->request('GET', Routes::CurrentUser->value, bearerToken: self::stringField($this->jsonBody($response)['accessToken']));
		$body = $this->jsonBody($me);
		self::assertSame('jane@example.com', $body['email']);
		self::assertSame('Jane Doe', $body['name']);
		self::assertSame('User', $body['role']);
		self::assertFalse($body['hasPassword']);
		self::assertTrue($body['google']);
		self::assertTrue($body['emailVerified']);
		self::assertSame('en', $body['locale']);

		$passwordLogin = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => '']);
		self::assertSame(401, $passwordLogin->getStatusCode());
	}

	public function testANewAccountTakesTheBrowsersLanguage(): void
	{
		AppHarness::googleAuth()->identities['token-a'] = new GoogleIdentity('google-1', 'jana@example.com', 'Jana');

		$response = $this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-a', 'locale' => 'cs']);

		$me = $this->request('GET', Routes::CurrentUser->value, bearerToken: self::stringField($this->jsonBody($response)['accessToken']));
		self::assertSame('cs', $this->jsonBody($me)['locale']);
	}

	public function testSignInAgainFindsTheAccountByGoogleIdEvenWithAnotherEmail(): void
	{
		AppHarness::googleAuth()->identities['token-a'] = new GoogleIdentity('google-1', 'jane@example.com', 'Jane');
		AppHarness::googleAuth()->identities['token-b'] = new GoogleIdentity('google-1', 'jane.doe@example.com', 'Jane');

		$first = $this->jsonBody($this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-a']));
		$second = $this->jsonBody($this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-b']));

		self::assertSame($first['userId'], $second['userId']);
	}

	public function testLinksTheAccountWithTheVerifiedEmailAndKeepsItsPassword(): void
	{
		$user = Fixture::createUser('jane@example.com', 'Jane');
		AppHarness::googleAuth()->identities['token-a'] = new GoogleIdentity('google-1', 'jane@example.com', 'Someone Else');

		$response = $this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-a']);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame($user->id, $this->jsonBody($response)['userId']);
		$reloaded = $this->userRepository()->findById($user->id);
		self::assertNotNull($reloaded);
		self::assertSame('google-1', $reloaded->googleId);
		self::assertSame('Jane', $reloaded->name);
		$login = $this->request(
			'POST',
			Routes::AuthenticationLogin->value,
			['email' => 'jane@example.com', 'password' => Fixture::Password],
		);
		self::assertSame(200, $login->getStatusCode());
	}

	public function testLinkingAnUnverifiedAccountDropsItsPasswordAndSignsItOut(): void
	{
		$this->request(
			'POST',
			Routes::AuthenticationSignUp->value,
			['email' => 'jane@example.com', 'password' => 'Squatter1', 'name' => 'Not Jane'],
		);
		$squatter = $this->jsonBody(
			$this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Squatter1']),
		);
		AppHarness::googleAuth()->identities['token-a'] = new GoogleIdentity('google-1', 'jane@example.com', 'Jane');

		$response = $this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-a']);

		self::assertSame(200, $response->getStatusCode());
		self::assertSame($squatter['userId'], $this->jsonBody($response)['userId']);
		self::assertSame(
			401,
			$this->request('GET', Routes::CurrentUser->value, bearerToken: self::stringField($squatter['accessToken']))->getStatusCode(),
		);
		$login = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Squatter1']);
		self::assertSame(401, $login->getStatusCode());
		$me = $this->request('GET', Routes::CurrentUser->value, bearerToken: self::stringField($this->jsonBody($response)['accessToken']));
		self::assertTrue($this->jsonBody($me)['emailVerified']);
		self::assertFalse($this->jsonBody($me)['hasPassword']);
	}

	public function testAnAccountLinkedToAnotherGoogleAccountIsNotTakenOver(): void
	{
		AppHarness::googleAuth()->identities['token-a'] = new GoogleIdentity('google-1', 'jane@example.com', 'Jane');
		AppHarness::googleAuth()->identities['token-b'] = new GoogleIdentity('google-2', 'jane@example.com', 'Jane');
		$this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-a']);

		$response = $this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-b']);

		self::assertSame(401, $response->getStatusCode());
	}

	public function testInvalidTokenIsRefused(): void
	{
		$response = $this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'forged']);

		self::assertSame(401, $response->getStatusCode());
		self::assertSame(0, $this->userRepository()->select()->count());
	}

	public function testAGoogleOnlyAccountCannotChangeAPasswordItDoesNotHave(): void
	{
		AppHarness::googleAuth()->identities['token-a'] = new GoogleIdentity('google-1', 'jane@example.com', 'Jane');
		$auth = $this->jsonBody($this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'token-a']));

		$response = $this->request(
			'POST',
			Routes::CurrentUserPassword->value,
			['currentPassword' => '', 'newPassword' => 'NewSecret1'],
			bearerToken: self::stringField($auth['accessToken']),
		);

		self::assertSame(422, $response->getStatusCode());
	}

	private function userRepository(): UserRepository
	{
		$repository = AppHarness::container()->get(UserRepository::class);
		assert($repository instanceof UserRepository);

		return $repository;
	}
}
