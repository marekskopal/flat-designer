<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Project;
use const DATE_ATOM;

final readonly class ProjectDto
{
	/** @param list<FloorDto> $floors */
	public function __construct(
		public int $id,
		public string $name,
		public ?string $address,
		public ?string $description,
		public ?int $ownerId,
		public ?string $ownerName,
		public array $floors,
		public ?int $latestVersion,
		public int $revision,
		public string $createdAt,
		public string $updatedAt,
	) {
	}

	/** @param list<FloorDto> $floors */
	public static function fromEntity(Project $project, array $floors, ?int $latestVersion): self
	{
		return new self(
			id: $project->id,
			name: $project->name,
			address: $project->address,
			description: $project->description,
			ownerId: $project->owner?->id,
			ownerName: $project->owner?->name,
			floors: $floors,
			latestVersion: $latestVersion,
			revision: $project->revision,
			createdAt: $project->createdAt->format(DATE_ATOM),
			updatedAt: $project->updatedAt->format(DATE_ATOM),
		);
	}
}
