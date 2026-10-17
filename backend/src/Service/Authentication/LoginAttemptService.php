<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Authentication;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\UserRepository;
use FlatDesigner\Service\Authentication\Exception\AccountLockedException;

/** Locks an account for a growing period once failed sign-ins pass the threshold. */
final readonly class LoginAttemptService
{
	/** Backoff schedule (seconds) once the threshold is reached. The last value repeats, up to the cap. */
	private const array BackoffSchedule = [60, 120, 300, 900, 1800];

	public function __construct(private UserRepository $userRepository, private RateLimitConfig $config)
	{
	}

	public function assertNotLocked(User $user): void
	{
		if ($user->lockedUntil === null) {
			return;
		}

		$remaining = $user->lockedUntil->getTimestamp() - time();
		if ($remaining <= 0) {
			return;
		}

		throw new AccountLockedException($remaining);
	}

	public function recordFailure(User $user): void
	{
		$user->failedLoginAttempts++;

		$excess = $user->failedLoginAttempts - $this->config->loginThreshold;
		if ($excess >= 0) {
			$user->lockedUntil = new DateTimeImmutable('+' . $this->backoffSeconds($excess) . ' seconds');
		}

		$this->userRepository->persist($user);
	}

	public function recordSuccess(User $user): void
	{
		if ($user->failedLoginAttempts === 0 && $user->lockedUntil === null) {
			return;
		}

		$user->failedLoginAttempts = 0;
		$user->lockedUntil = null;
		$this->userRepository->persist($user);
	}

	private function backoffSeconds(int $excess): int
	{
		$seconds = self::BackoffSchedule[$excess] ?? self::BackoffSchedule[count(self::BackoffSchedule) - 1];

		return min($seconds, $this->config->loginBackoffCapSeconds);
	}
}
