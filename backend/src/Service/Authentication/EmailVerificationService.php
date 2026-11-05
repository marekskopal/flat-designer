<?php

declare(strict_types=1);

namespace Izbik\Service\Authentication;

use DateTimeImmutable;
use Izbik\Model\Entity\EmailVerificationToken;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\EmailVerificationTokenRepository;
use Izbik\Service\Mail\EmailVerificationEmail;
use Izbik\Service\Mail\MailConfig;
use Izbik\Service\Mail\MailOutbox;
use Izbik\Service\Provider\UserProvider;
use Izbik\Service\Translator\Translator;
use RuntimeException;
use SensitiveParameter;

/**
 * Confirms that an account's email address is its owner's: a one-time link to the app's `/verify-email?token=…` page,
 * valid for a day, sent on sign-up and on request (at most `MaxPerHour` an hour). Nothing is sent while mail is off or the
 * address is verified already. An unverified account may use the app; the flag decides whether a Google sign-in with
 * the same email may keep the account's password (`AuthenticationController`).
 */
final readonly class EmailVerificationService
{
	private const int LifetimeHours = 24;
	private const int MaxPerHour = 5;

	public function __construct(
		private Translator $translator,
		private EmailVerificationTokenRepository $tokenRepository,
		private UserProvider $userProvider,
		private MailOutbox $mailOutbox,
		private MailConfig $mailConfig,
	) {
	}

	/** Mails a verification link; false when none was sent (mail off, already verified, too many this hour). */
	public function requestVerification(User $user, string $appUrl): bool
	{
		if ($user->emailVerified || !$this->mailConfig->isEnabled()) {
			return false;
		}

		$now = new DateTimeImmutable();
		if ($this->tokenRepository->countByUserSince($user->id, $now->modify('-1 hour')) >= self::MaxPerHour) {
			return false;
		}

		$token = bin2hex(random_bytes(32));
		$verificationToken = new EmailVerificationToken(
			user: $user,
			tokenHash: self::hash($token),
			email: $user->email,
			expiresAt: $now->modify('+' . self::LifetimeHours . ' hours'),
		);
		$verificationToken->createdAt = $now;
		$verificationToken->updatedAt = $now;
		$this->tokenRepository->persist($verificationToken);

		$this->mailOutbox->queue(EmailVerificationEmail::create(
			translator: $this->translator,
			user: $user,
			verifyUrl: rtrim($appUrl, '/') . '/verify-email?token=' . $token,
			lifetimeHours: self::LifetimeHours,
		));

		return true;
	}

	/**
	 * Marks the link's account verified and voids its other links. A link sent to an address the account no longer has
	 * proves nothing about the current one.
	 */
	public function confirm(#[SensitiveParameter] string $token): User
	{
		$verificationToken = $token !== '' ? $this->tokenRepository->findOneByTokenHash(self::hash($token)) : null;
		if ($verificationToken === null) {
			throw new RuntimeException('This verification link is invalid.');
		}
		if ($verificationToken->usedAt !== null) {
			throw new RuntimeException('This verification link has already been used.');
		}
		$now = new DateTimeImmutable();
		if ($verificationToken->expiresAt < $now) {
			throw new RuntimeException('This verification link has expired.');
		}
		if ($verificationToken->email !== $verificationToken->user->email) {
			throw new RuntimeException('This verification link is for an earlier email address.');
		}

		$user = $this->userProvider->markEmailVerified($verificationToken->user);
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
