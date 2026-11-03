<?php

declare(strict_types=1);

namespace Izbik\Service\Authentication;

use DateTimeImmutable;
use Izbik\Model\Entity\PasswordResetToken;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\PasswordResetTokenRepository;
use Izbik\Service\Mail\MailOutbox;
use Izbik\Service\Mail\PasswordResetEmail;
use Izbik\Service\Provider\UserProvider;
use RuntimeException;
use SensitiveParameter;

/**
 * Password reset by email: a one-time link to the app's `/reset-password?token=…` page, valid for an hour. Asking for one
 * answers the same whether or not the address has an account (no account enumeration), and at most
 * `RateLimitConfig::$passwordResetsPerHour` mails go to one account an hour. A reset also unlocks the account, signs it
 * out everywhere (`UserProvider::updatePassword()`) and voids the account's other links.
 */
final readonly class PasswordResetService
{
	private const int LifetimeMinutes = 60;

	public function __construct(
		private PasswordResetTokenRepository $tokenRepository,
		private UserProvider $userProvider,
		private LoginAttemptService $loginAttempts,
		private MailOutbox $mailOutbox,
		private RateLimitConfig $rateLimitConfig,
	) {
	}

	/** Mails a reset link to the account with `$email`, if there is one; `$appUrl` is the base URL of the app's pages. */
	public function requestReset(string $email, string $appUrl): void
	{
		$user = $this->userProvider->getUserByEmail($email);
		if ($user === null) {
			return;
		}

		$now = new DateTimeImmutable();
		if ($this->tokenRepository->countByUserSince($user->id, $now->modify('-1 hour')) >= $this->rateLimitConfig->passwordResetsPerHour) {
			return;
		}

		$token = bin2hex(random_bytes(32));
		$resetToken = new PasswordResetToken(
			user: $user,
			tokenHash: self::hash($token),
			expiresAt: $now->modify('+' . self::LifetimeMinutes . ' minutes'),
		);
		$resetToken->createdAt = $now;
		$resetToken->updatedAt = $now;
		$this->tokenRepository->persist($resetToken);

		$this->mailOutbox->queue(PasswordResetEmail::create(
			recipientEmail: $user->email,
			userName: $user->name,
			resetUrl: rtrim($appUrl, '/') . '/reset-password?token=' . $token,
			lifetimeMinutes: self::LifetimeMinutes,
		));
	}

	/**
	 * Sets the new password of the link's account, already checked against `PasswordValidator`.
	 */
	public function confirmReset(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): User
	{
		$resetToken = $token !== '' ? $this->tokenRepository->findOneByTokenHash(self::hash($token)) : null;
		if ($resetToken === null) {
			throw new RuntimeException('This reset link is invalid.');
		}
		if ($resetToken->usedAt !== null) {
			throw new RuntimeException('This reset link has already been used.');
		}
		$now = new DateTimeImmutable();
		if ($resetToken->expiresAt < $now) {
			throw new RuntimeException('This reset link has expired.');
		}

		$user = $this->userProvider->updatePassword($resetToken->user, $password);
		$this->loginAttempts->recordSuccess($user);
		$this->userProvider->recordLogin($user);

		foreach ($this->tokenRepository->findUnusedByUser($user->id) as $unused) {
			$unused->usedAt = $now;
			$unused->updatedAt = $now;
			$this->tokenRepository->persist($unused);
		}

		return $user;
	}

	private static function hash(string $token): string
	{
		return hash('sha256', $token);
	}
}
