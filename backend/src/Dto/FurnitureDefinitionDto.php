<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * A furniture definition; `definition` is its JSON (format 1) as stored, `userId` the library it is in (scope `user`),
 * `canEdit` whether the caller may change it, `itemCount` how many items (in all projects) are drawn from it — only when
 * one definition is read, null in lists.
 */
final readonly class FurnitureDefinitionDto
{
	public function __construct(
		public int $id,
		public string $name,
		public string $description,
		public FurnitureScopeEnum $scope,
		public ?int $userId,
		public ?string $userName,
		public ?int $projectId,
		public mixed $definition,
		public bool $canEdit,
		public ?int $itemCount,
		public ?string $createdByName,
		public string $createdAt,
		public string $updatedAt,
	) {
	}

	public static function fromEntity(FurnitureDefinition $definition, bool $canEdit, ?int $itemCount = null): self
	{
		return new self(
			id: $definition->id,
			name: $definition->name,
			description: $definition->description,
			scope: $definition->scope(),
			userId: $definition->user?->id,
			userName: $definition->user?->name,
			projectId: $definition->project?->id,
			// Decoded to objects, so empty ones stay `{}` in the response.
			definition: json_decode($definition->definition, flags: JSON_THROW_ON_ERROR),
			canEdit: $canEdit,
			itemCount: $itemCount,
			createdByName: $definition->createdBy?->name,
			createdAt: $definition->createdAt->format(DATE_ATOM),
			updatedAt: $definition->updatedAt->format(DATE_ATOM),
		);
	}
}
