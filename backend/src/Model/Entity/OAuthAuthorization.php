<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\OAuthAuthorizationRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * One step of an OAuth grant: an authorization code, or an access/refresh token pair. Codes and tokens
 * are stored as SHA-256 hashes and are single use (`revoked` flips when consumed). Rows descending from
 * one code share `familyId`, so replaying a used code or refresh token revokes the whole lineage.
 */
#[Entity(repositoryClass: OAuthAuthorizationRepository::class, table: 'oauth_authorizations')]
class OAuthAuthorization extends AEntity
{
	public function __construct(
		#[Column(type: Type::String, size: 128)]
		public readonly string $clientId,
		#[ManyToOne(entityClass: User::class)]
		public readonly User $user,
		#[Column(type: Type::String, size: 64, nullable: true, default: null)]
		public ?string $authorizationCodeHash = null,
		#[Column(type: Type::String, nullable: true, default: null)]
		public ?string $codeChallenge = null,
		#[Column(type: Type::String, size: 10, nullable: true, default: null)]
		public ?string $codeChallengeMethod = null,
		#[Column(type: Type::Text, nullable: true, default: null)]
		public ?string $redirectUri = null,
		#[Column(type: Type::String, size: 64, nullable: true, default: null)]
		public ?string $accessTokenHash = null,
		#[Column(type: Type::String, size: 64, nullable: true, default: null)]
		public ?string $refreshTokenHash = null,
		#[Column(type: Type::Int, nullable: true, default: null)]
		public ?int $accessTokenExpires = null,
		#[Column(type: Type::Int, nullable: true, default: null)]
		public ?int $refreshTokenExpires = null,
		#[Column(type: Type::Int, nullable: true, default: null)]
		public ?int $codeExpires = null,
		#[Column(type: Type::Boolean, default: false)]
		public bool $revoked = false,
		#[Column(type: Type::String, size: 32, nullable: true, default: null)]
		public ?string $familyId = null,
	) {
	}
}
