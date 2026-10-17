<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Provider;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\UserRepository;
use SensitiveParameter;
use const PASSWORD_BCRYPT;

/** Emails are stored lower-cased; callers pass them already validated (`UserFieldValidator`). */
final readonly class UserProvider
{
	public function __construct(private UserRepository $userRepository)
	{
	}

	public function getUser(int $userId): ?User
	{
		return $this->userRepository->findById($userId);
	}

	public function getUserByEmail(string $email): ?User
	{
		return $this->userRepository->findByEmail(mb_strtolower(trim($email)));
	}

	public function createUser(
		string $email,
		#[SensitiveParameter] string $password,
		string $name,
		UserRoleEnum $role = UserRoleEnum::User,
	): User {
		$now = new DateTimeImmutable();
		$user = new User(email: mb_strtolower($email), password: self::hash($password), name: $name, role: $role);
		$user->createdAt = $now;
		$user->updatedAt = $now;

		$this->userRepository->persist($user);

		return $user;
	}

	public function updateName(User $user, string $name): User
	{
		$user->name = $name;
		$user->updatedAt = new DateTimeImmutable();
		$this->userRepository->persist($user);

		return $user;
	}

	/** Also bumps `tokenVersion`, which signs the user out everywhere else. */
	public function updatePassword(User $user, #[SensitiveParameter] string $password): User
	{
		$user->password = self::hash($password);
		$user->tokenVersion++;
		$user->updatedAt = new DateTimeImmutable();
		$this->userRepository->persist($user);

		return $user;
	}

	public function recordLogin(User $user): void
	{
		$user->lastLoginAt = new DateTimeImmutable();
		$this->userRepository->persist($user);
	}

	private static function hash(#[SensitiveParameter] string $password): string
	{
		return password_hash($password, PASSWORD_BCRYPT);
	}
}
