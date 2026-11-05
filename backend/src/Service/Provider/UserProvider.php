<?php

declare(strict_types=1);

namespace Izbik\Service\Provider;

use DateTimeImmutable;
use Izbik\Model\Entity\Enum\ElectricalSymbolsEnum;
use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Model\Entity\Enum\UserRoleEnum;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\UserRepository;
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

	public function getUserByGoogleId(string $googleId): ?User
	{
		return $this->userRepository->findByGoogleId($googleId);
	}

	public function createUser(
		string $email,
		#[SensitiveParameter] string $password,
		string $name,
		UserRoleEnum $role = UserRoleEnum::User,
		bool $emailVerified = false,
		LocaleEnum $locale = LocaleEnum::En,
	): User {
		$now = new DateTimeImmutable();
		$user = new User(email: mb_strtolower($email), password: self::hash($password), name: $name, role: $role);
		$user->emailVerified = $emailVerified;
		$user->locale = $locale;
		$user->createdAt = $now;
		$user->updatedAt = $now;

		$this->userRepository->persist($user);

		return $user;
	}

	/** An account signing in with Google only, with no password until it sets one by a reset link. */
	public function createUserFromGoogle(string $email, string $name, string $googleId, LocaleEnum $locale = LocaleEnum::En): User
	{
		$now = new DateTimeImmutable();
		$user = new User(email: mb_strtolower($email), password: null, name: mb_substr($name, 0, 255));
		$user->googleId = $googleId;
		$user->locale = $locale;
		$user->emailVerified = true;
		$user->createdAt = $now;
		$user->updatedAt = $now;

		$this->userRepository->persist($user);

		return $user;
	}

	/**
	 * Links an existing account (found by the email Google verified) to the Google account, which proves the address. An
	 * account whose address was not verified may have been signed up by someone else with the owner's email, so its
	 * password is dropped and every token issued so far revoked; a verified account keeps its password.
	 */
	public function linkGoogleAccount(User $user, string $googleId): User
	{
		if (!$user->emailVerified) {
			$user->password = null;
			$user->tokenVersion++;
			$user->emailVerified = true;
		}
		$user->googleId = $googleId;
		$user->updatedAt = new DateTimeImmutable();
		$this->userRepository->persist($user);

		return $user;
	}

	/** The fields a user changes on their own account, persisted once; null leaves one as it is, none persists nothing. */
	public function updateProfile(User $user, ?string $name, ?ElectricalSymbolsEnum $symbols, ?LocaleEnum $locale = null): User
	{
		if ($name === null && $symbols === null && $locale === null) {
			return $user;
		}
		$user->name = $name ?? $user->name;
		$user->electricalSymbols = $symbols ?? $user->electricalSymbols;
		$user->locale = $locale ?? $user->locale;
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

	public function markEmailVerified(User $user): User
	{
		if (!$user->emailVerified) {
			$user->emailVerified = true;
			$user->updatedAt = new DateTimeImmutable();
			$this->userRepository->persist($user);
		}

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
