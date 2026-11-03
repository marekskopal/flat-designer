<?php

declare(strict_types=1);

namespace Izbik\Model\Entity;

use DateTimeImmutable;
use Izbik\Model\Repository\PasswordResetTokenRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A password reset link sent by email (`PasswordResetService`). The token is stored as its SHA-256 hash; a link is used
 * once, until `expiresAt`.
 */
#[Entity(repositoryClass: PasswordResetTokenRepository::class)]
class PasswordResetToken extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: User::class)]
		public readonly User $user,
		#[Column(type: Type::String, size: 64)]
		public readonly string $tokenHash,
		#[Column(type: Type::Timestamp)]
		public readonly DateTimeImmutable $expiresAt,
		#[Column(type: Type::Timestamp, nullable: true)]
		public ?DateTimeImmutable $usedAt = null,
	) {
	}
}
