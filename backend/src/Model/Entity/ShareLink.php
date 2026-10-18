<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Repository\ShareLinkRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Public, view-only link (`/s/{token}`) to one floor of a project as it was when the link was made: creating it
 * saves a version, and `floorId` / `floorName` are the floor's id and name inside that version's snapshot. Refreshing
 * the link saves a new version and points the link at it (same token). `settings` is the JSON of
 * `ShareSettingsDto` (allowed views, start view, camera, display). Past `expiresAt` the link answers 404.
 */
#[Entity(repositoryClass: ShareLinkRepository::class)]
class ShareLink extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Project::class)]
		public readonly Project $project,
		#[ManyToOne(entityClass: ProjectVersion::class)]
		public ProjectVersion $version,
		#[Column(type: Type::Int)]
		public readonly int $floorId,
		#[Column(type: Type::String)]
		public string $floorName,
		#[Column(type: Type::String, size: 64)]
		public readonly string $token,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::Text)]
		public string $settings,
		#[Column(type: Type::Timestamp, nullable: true)]
		public ?DateTimeImmutable $expiresAt,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public ?User $createdBy,
	) {
	}

	public function isExpired(DateTimeImmutable $now): bool
	{
		return $this->expiresAt !== null && $this->expiresAt <= $now;
	}
}
