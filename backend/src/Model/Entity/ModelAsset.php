<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Repository\ModelAssetRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A 3D model of a bought product (a chair, a lamp, a tap, a plant) as a glTF binary in object storage under
 * `storageKey`. `width` × `depth` × `height` is its native size in mm (glTF metres: x across, y up, z towards its
 * front). The scope follows from the owners like a furniture definition's: `user` = that user's library, `project` =
 * one project, neither = global. `license`, `author` and `sourceUrl` credit third-party models. Soft-deleted.
 */
#[Entity(repositoryClass: ModelAssetRepository::class)]
class ModelAsset extends AEntity
{
	/** Set when deleted; the row and the file stay so items using it keep drawing it, but it cannot be picked any more. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	public function __construct(
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public readonly ?User $user,
		#[ManyToOne(entityClass: Project::class, nullable: true)]
		public readonly ?Project $project,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::Text)]
		public string $description,
		#[Column(type: Type::String)]
		public readonly string $filename,
		#[Column(type: Type::String, size: 512)]
		public readonly string $storageKey,
		#[Column(type: Type::Int)]
		public readonly int $size,
		#[Column(type: Type::Int)]
		public readonly int $width,
		#[Column(type: Type::Int)]
		public readonly int $depth,
		#[Column(type: Type::Int)]
		public readonly int $height,
		#[Column(type: Type::String)]
		public string $license,
		#[Column(type: Type::String)]
		public string $author,
		#[Column(type: Type::String, size: 512)]
		public string $sourceUrl,
		#[ManyToOne(entityClass: User::class, nullable: true)]
		public ?User $createdBy,
	) {
	}

	public function scope(): FurnitureScopeEnum
	{
		return match (true) {
			$this->project !== null => FurnitureScopeEnum::Project,
			$this->user !== null => FurnitureScopeEnum::User,
			default => FurnitureScopeEnum::Global,
		};
	}
}
