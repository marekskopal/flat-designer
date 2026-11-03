<?php

declare(strict_types=1);

namespace Izbik\Model\Entity;

use DateTimeImmutable;
use Izbik\Model\Repository\EmailVerificationTokenRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A link sent to confirm an account's email address (`EmailVerificationService`). The token is stored as its SHA-256
 * hash, with the `email` it went to; a link is used once, until `expiresAt`.
 */
#[Entity(repositoryClass: EmailVerificationTokenRepository::class)]
class EmailVerificationToken extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: User::class)]
		public readonly User $user,
		#[Column(type: Type::String, size: 64)]
		public readonly string $tokenHash,
		#[Column(type: Type::String)]
		public readonly string $email,
		#[Column(type: Type::Timestamp)]
		public readonly DateTimeImmutable $expiresAt,
		#[Column(type: Type::Timestamp, nullable: true)]
		public ?DateTimeImmutable $usedAt = null,
	) {
	}
}
