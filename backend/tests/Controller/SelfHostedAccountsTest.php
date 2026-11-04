<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller;

use Izbik\Command\UserResetPasswordCommand;
use Izbik\Controller\AuthenticationController;
use Izbik\Controller\CurrentUserController;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Model\Repository\UserRepository;
use Izbik\Route\Routes;
use Izbik\Service\Account\AccountDeletion;
use Izbik\Service\Authentication\GoogleIdentity;
use Izbik\Tests\Support\AppHarness;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\CommandTester;

/** Running a server without mail and without open sign-up: closing sign-up, resetting passwords, deleting accounts. */
#[CoversClass(AuthenticationController::class)]
#[CoversClass(CurrentUserController::class)]
#[CoversClass(AccountDeletion::class)]
#[CoversClass(UserResetPasswordCommand::class)]
final class SelfHostedAccountsTest extends IntegrationTestCase
{
	public function testTheOptionsTellWhetherSignUpIsOpen(): void
	{
		self::assertTrue($this->jsonBody($this->request('GET', Routes::AuthenticationOptions->value))['signUp']);

		AppHarness::signUp()->enabled = false;

		self::assertFalse($this->jsonBody($this->request('GET', Routes::AuthenticationOptions->value))['signUp']);
	}

	public function testClosedSignUpCreatesNoAccount(): void
	{
		AppHarness::signUp()->enabled = false;

		$response = $this->request('POST', Routes::AuthenticationSignUp->value, [
			'email' => 'new@example.com',
			'password' => 'Secret123',
			'name' => 'New',
		]);

		self::assertSame(403, $response->getStatusCode());
		self::assertNull(Fixture::service(UserRepository::class)->findByEmail('new@example.com'));
	}

	public function testClosedSignUpLetsGoogleSignInExistingAccountsOnly(): void
	{
		Fixture::createUser('jane@example.com');
		AppHarness::signUp()->enabled = false;
		AppHarness::googleAuth()->identities['jane'] = new GoogleIdentity('google-1', 'jane@example.com', 'Jane');
		AppHarness::googleAuth()->identities['stranger'] = new GoogleIdentity('google-2', 'stranger@example.com', 'Stranger');

		self::assertSame(200, $this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'jane'])->getStatusCode());
		self::assertSame(403, $this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'stranger'])->getStatusCode());
		self::assertNull(Fixture::service(UserRepository::class)->findByEmail('stranger@example.com'));
	}

	public function testAUserDeletesTheirAccountWithTheirProjects(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$other = Fixture::createUser();
		Fixture::createProject($user, 'Mine');
		$kept = Fixture::createProject($other, 'Theirs');
		$token = Fixture::accessTokenFor($user);

		$wrong = $this->request('DELETE', Routes::CurrentUser->value, ['password' => 'Wrong1234'], bearerToken: $token);
		self::assertSame(422, $wrong->getStatusCode());

		$deleted = $this->request('DELETE', Routes::CurrentUser->value, ['password' => Fixture::Password], bearerToken: $token);

		self::assertSame(200, $deleted->getStatusCode());
		self::assertNull(Fixture::service(UserRepository::class)->findByEmail('jane@example.com'));
		$projects = Fixture::service(ProjectRepository::class);
		self::assertSame(0, $projects->countByOwner($user->id));
		self::assertNotNull($projects->findById($kept->id));
		self::assertSame(401, $this->request('GET', Routes::CurrentUser->value, bearerToken: $token)->getStatusCode());
	}

	public function testAGoogleOnlyAccountConfirmsWithItsEmail(): void
	{
		AppHarness::googleAuth()->identities['jane'] = new GoogleIdentity('google-1', 'jane@example.com', 'Jane');
		$token = self::stringField(
			$this->jsonBody($this->request('POST', Routes::AuthenticationGoogleLogin->value, ['idToken' => 'jane']))['accessToken'],
		);

		self::assertSame(
			422,
			$this->request('DELETE', Routes::CurrentUser->value, ['email' => 'other@example.com'], bearerToken: $token)->getStatusCode(),
		);
		self::assertSame(
			200,
			$this->request('DELETE', Routes::CurrentUser->value, ['email' => 'Jane@Example.com'], bearerToken: $token)->getStatusCode(),
		);
	}

	public function testTheLastAdminCannotDeleteTheirAccount(): void
	{
		$admin = Fixture::createAdmin();

		$response = $this->request('DELETE', Routes::CurrentUser->value, ['password' => Fixture::Password], authenticatedAs: $admin);

		self::assertSame(409, $response->getStatusCode());
		self::assertNotNull(Fixture::service(UserRepository::class)->findById($admin->id));
	}

	public function testTheConsoleSetsAPasswordAndSignsTheUserOut(): void
	{
		$user = Fixture::createUser('jane@example.com');
		$token = Fixture::accessTokenFor($user);
		// A request first, which clears the identity map: the command writes through an app of its own.
		self::assertSame(200, $this->request('GET', Routes::CurrentUser->value, bearerToken: $token)->getStatusCode());
		$tester = new CommandTester(new UserResetPasswordCommand());

		self::assertSame(1, $tester->execute(['email' => 'jane@example.com', '--password' => 'weak'], ['interactive' => false]));
		self::assertSame(1, $tester->execute(['email' => 'nobody@example.com', '--password' => 'Changed123'], ['interactive' => false]));
		self::assertSame(0, $tester->execute(['email' => 'jane@example.com', '--password' => 'Changed123'], ['interactive' => false]));

		self::assertSame(401, $this->request('GET', Routes::CurrentUser->value, bearerToken: $token)->getStatusCode());
		$login = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Changed123']);
		self::assertSame(200, $login->getStatusCode());
	}
}
