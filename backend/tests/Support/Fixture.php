<?php

declare(strict_types=1);

namespace FlatDesigner\Tests\Support;

use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Authentication\TokenService;
use FlatDesigner\Service\Provider\UserProvider;

final class Fixture
{
	public const string Password = 'TestPass1!';

	private static int $userCounter = 0;

	public static function reset(): void
	{
		self::$userCounter = 0;
	}

	public static function createUser(?string $email = null, string $name = 'Test User', UserRoleEnum $role = UserRoleEnum::User,): User
	{
		self::$userCounter++;

		return self::userProvider()->createUser($email ?? 'user' . self::$userCounter . '@example.com', self::Password, $name, $role);
	}

	public static function createAdmin(?string $email = null): User
	{
		return self::createUser($email, 'Admin', UserRoleEnum::Admin);
	}

	public static function accessTokenFor(User $user): string
	{
		return self::tokenService()->createAuthentication($user)->accessToken;
	}

	public static function refreshTokenFor(User $user): string
	{
		return self::tokenService()->createAuthentication($user)->refreshToken;
	}

	private static function userProvider(): UserProvider
	{
		$userProvider = AppHarness::container()->get(UserProvider::class);
		assert($userProvider instanceof UserProvider);

		return $userProvider;
	}

	private static function tokenService(): TokenService
	{
		$tokenService = AppHarness::container()->get(TokenService::class);
		assert($tokenService instanceof TokenService);

		return $tokenService;
	}
}
