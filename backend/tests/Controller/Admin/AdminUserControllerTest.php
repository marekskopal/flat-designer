<?php

declare(strict_types=1);

namespace Izbik\Tests\Controller\Admin;

use Izbik\Controller\Admin\AdminUserController;
use Izbik\Route\Routes;
use Izbik\Service\Admin\AdminUserService;
use Izbik\Tests\Support\Fixture;
use Izbik\Tests\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AdminUserController::class)]
#[CoversClass(AdminUserService::class)]
final class AdminUserControllerTest extends IntegrationTestCase
{
	public function testRegularUserIsForbidden(): void
	{
		$user = Fixture::createUser();
		$other = Fixture::createUser();

		self::assertSame(403, $this->request('GET', Routes::AdminUsers->value, authenticatedAs: $user)->getStatusCode());
		self::assertSame(403, $this->request('PATCH', self::userPath($other->id), ['role' => 'Admin'], $user)->getStatusCode());
		self::assertSame(403, $this->request('DELETE', self::userPath($other->id), authenticatedAs: $user)->getStatusCode());
	}

	public function testAdminListsUsers(): void
	{
		$admin = Fixture::createAdmin();
		Fixture::createUser('jane@example.com');

		$users = $this->jsonList($this->request('GET', Routes::AdminUsers->value, authenticatedAs: $admin));

		self::assertSame([$admin->email, 'jane@example.com'], array_column($users, 'email'));
	}

	public function testAdminUpdatesUser(): void
	{
		$admin = Fixture::createAdmin();
		$user = Fixture::createUser();

		$response = $this->request(
			'PATCH',
			self::userPath($user->id),
			['name' => 'Promoted', 'email' => 'P@Example.com', 'role' => 'Admin'],
			$admin,
		);

		self::assertSame(200, $response->getStatusCode());
		$body = $this->jsonBody($response);
		self::assertSame('Promoted', $body['name']);
		self::assertSame('p@example.com', $body['email']);
		self::assertSame('Admin', $body['role']);
	}

	public function testUpdateRejectsDuplicateEmailAndUnknownRole(): void
	{
		$admin = Fixture::createAdmin();
		$user = Fixture::createUser();

		self::assertSame(409, $this->request('PATCH', self::userPath($user->id), ['email' => $admin->email], $admin)->getStatusCode());
		self::assertSame(422, $this->request('PATCH', self::userPath($user->id), ['role' => 'Root'], $admin)->getStatusCode());
	}

	public function testLastAdminCannotBeDemoted(): void
	{
		$admin = Fixture::createAdmin();

		self::assertSame(409, $this->request('PATCH', self::userPath($admin->id), ['role' => 'User'], $admin)->getStatusCode());

		$second = Fixture::createAdmin();
		self::assertSame(200, $this->request('PATCH', self::userPath($admin->id), ['role' => 'User'], $second)->getStatusCode());
	}

	public function testAdminDeletesUserButNotSelf(): void
	{
		$admin = Fixture::createAdmin();
		$user = Fixture::createUser();

		self::assertSame(409, $this->request('DELETE', self::userPath($admin->id), authenticatedAs: $admin)->getStatusCode());
		self::assertSame(200, $this->request('DELETE', self::userPath($user->id), authenticatedAs: $admin)->getStatusCode());
		self::assertSame(404, $this->request('DELETE', self::userPath($user->id), authenticatedAs: $admin)->getStatusCode());
	}

	public function testUserOwningProjectsCannotBeDeleted(): void
	{
		$admin = Fixture::createAdmin();
		$user = Fixture::createUser();
		Fixture::createProject($user);

		self::assertSame(409, $this->request('DELETE', self::userPath($user->id), authenticatedAs: $admin)->getStatusCode());
	}

	public function testAdminAddsAUser(): void
	{
		$admin = Fixture::createAdmin();
		$user = Fixture::createUser();
		$body = ['name' => 'Jane', 'email' => 'Jane@Example.com', 'password' => 'Secret123', 'role' => 'User'];

		self::assertSame(403, $this->request('POST', Routes::AdminUsers->value, $body, $user)->getStatusCode());
		$created = $this->request('POST', Routes::AdminUsers->value, $body, $admin);

		self::assertSame(201, $created->getStatusCode());
		self::assertSame(['jane@example.com', 'User', true], [
			$this->jsonBody($created)['email'],
			$this->jsonBody($created)['role'],
			$this->jsonBody($created)['emailVerified'],
		]);
		$login = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Secret123']);
		self::assertSame(200, $login->getStatusCode());
		self::assertSame(409, $this->request('POST', Routes::AdminUsers->value, $body, $admin)->getStatusCode());
		self::assertSame(422, $this->request('POST', Routes::AdminUsers->value, ['password' => 'weak'] + $body, $admin)->getStatusCode());
	}

	public function testAdminSetsAPasswordWhichSignsTheUserOut(): void
	{
		$admin = Fixture::createAdmin();
		$user = Fixture::createUser('jane@example.com');
		$token = Fixture::accessTokenFor($user);

		self::assertSame(422, $this->request('PATCH', self::userPath($user->id), ['password' => 'weak'], $admin)->getStatusCode());
		self::assertSame(422, $this->request('PATCH', self::userPath($admin->id), ['password' => 'Changed123'], $admin)->getStatusCode());
		self::assertSame(200, $this->request('PATCH', self::userPath($user->id), ['password' => 'Changed123'], $admin)->getStatusCode());

		self::assertSame(401, $this->request('GET', Routes::CurrentUser->value, bearerToken: $token)->getStatusCode());
		$login = $this->request('POST', Routes::AuthenticationLogin->value, ['email' => 'jane@example.com', 'password' => 'Changed123']);
		self::assertSame(200, $login->getStatusCode());
	}

	private static function userPath(int $userId): string
	{
		return str_replace('{userId:number}', (string) $userId, Routes::AdminUser->value);
	}
}
