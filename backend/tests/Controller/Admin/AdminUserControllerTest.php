<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Controller\Admin;

use FlatDesigner\Controller\Admin\AdminUserController;
use FlatDesigner\Route\Routes;
use FlatDesigner\Service\Admin\AdminUserService;
use FlatDesigner\Tests\Support\Fixture;
use FlatDesigner\Tests\Support\IntegrationTestCase;
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

	private static function userPath(int $userId): string
	{
		return str_replace('{userId:number}', (string) $userId, Routes::AdminUser->value);
	}
}
