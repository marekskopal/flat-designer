<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\OAuthClientRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/** MCP client registered through OAuth dynamic client registration (public client, PKCE only). */
#[Entity(repositoryClass: OAuthClientRepository::class, table: 'oauth_clients')]
class OAuthClient extends AEntity
{
	public function __construct(
		#[Column(type: Type::String, size: 128)]
		public readonly string $clientId,
		#[Column(type: Type::String)]
		public string $clientName,
		/** JSON list of allowed redirect URIs. */
		#[Column(type: Type::Text)]
		public string $redirectUris,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public readonly ?User $user,
	) {
	}
}
