<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\User;
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
		);
	}
}
