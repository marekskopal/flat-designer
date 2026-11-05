<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\User;
use const DATE_ATOM;

final readonly class UserDto
{
	public function __construct(
		public int $id,
		public string $email,
		public string $name,
		public string $role,
		public ?string $lastLoginAt,
		public string $createdAt,
		public string $electricalSymbols,
		public bool $hasPassword,
		public bool $google,
		public bool $emailVerified,
		public string $locale,
	) {
	}

	public static function fromEntity(User $user): self
	{
		return new self(
			id: $user->id,
			email: $user->email,
			name: $user->name,
			role: $user->role->value,
			lastLoginAt: $user->lastLoginAt?->format(DATE_ATOM),
			createdAt: $user->createdAt->format(DATE_ATOM),
			electricalSymbols: $user->electricalSymbols->value,
			hasPassword: $user->password !== null,
			google: $user->googleId !== null,
			emailVerified: $user->emailVerified,
			locale: $user->locale->value,
		);
	}
}
