<?php

declare(strict_types=1);

namespace Izbik\Model\Entity;

use DateTimeImmutable;
use Izbik\Model\Entity\Enum\ElectricalSymbolsEnum;
use Izbik\Model\Entity\Enum\UserRoleEnum;
use Izbik\Model\Repository\UserRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Enum\Type;

/**
 * Account signing in with email + password (bcrypt) or with Google (`googleId`, the Google account's `sub`; such an
 * account may have no password until it sets one by a reset link). `tokenVersion` is embedded in every issued JWT;
 * bumping it (password change) revokes all tokens issued before. `failedLoginAttempts` and
 * `lockedUntil` drive the sign-in backoff. `emailVerified`: the address is proven (a verification or reset link, Google,
 * `admin:create`). `electricalSymbols` picks the electrical plan's symbol set.
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

	#[ColumnEnum(enum: ElectricalSymbolsEnum::class, default: ElectricalSymbolsEnum::Iec)]
	public ElectricalSymbolsEnum $electricalSymbols = ElectricalSymbolsEnum::Iec;

	#[Column(type: Type::String, nullable: true)]
	public ?string $googleId = null;

	#[Column(type: Type::Boolean, default: false)]
	public bool $emailVerified = false;

	public function __construct(
		#[Column(type: Type::String)]
		public string $email,
		#[Column(type: Type::String, nullable: true)]
		public ?string $password,
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
