<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\UserRoleEnum;
use FlatDesigner\Model\Repository\UserRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Enum\Type;

/**
 * Account signing in with email + password (bcrypt). `tokenVersion` is embedded in every issued JWT;
 * bumping it (password change) revokes all tokens issued before. `failedLoginAttempts` and
 * `lockedUntil` drive the sign-in backoff.
 */
#[Entity(repositoryClass: UserRepository::class)]
class User extends AEntity
{
	#[Column(type: Type::Int, default: 0)]
	public int $failedLoginAttempts = 0;

	#[Column(type: Type::Int, default: 0)]
	public int $tokenVersion = 0;

	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $lockedUntil = null;

	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $lastLoginAt = null;

	public function __construct(
		#[Column(type: Type::String)]
		public string $email,
		#[Column(type: Type::String)]
		public string $password,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: UserRoleEnum::class, default: UserRoleEnum::User)]
		public UserRoleEnum $role = UserRoleEnum::User,
	) {
	}

	public function isAdmin(): bool
	{
		return $this->role === UserRoleEnum::Admin;
	}
}
