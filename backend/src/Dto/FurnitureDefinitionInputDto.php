<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Service\Request\BodyReader;

/**
 * Body of furniture definition create and update. `scope` (default `user`: the author's library) and `projectId` (for
 * `project`) belong to create only — an update refuses them; to move a definition elsewhere, copy it.
 */
final readonly class FurnitureDefinitionInputDto implements ArrayFactoryInterface
{
	/** @param array<string, mixed> $definition */
	public function __construct(
		public string $name,
		public string $description,
		public ?FurnitureScopeEnum $scope,
		public ?int $projectId,
		public array $definition,
	) {
	}

	public static function fromArray(array $data): static
	{
		return new self(
			name: BodyReader::string($data, 'name'),
			description: BodyReader::optionalString($data, 'description') ?? '',
			scope: FurnitureScopeInputDto::readScope($data),
			projectId: BodyReader::optionalInt($data, 'projectId'),
			definition: BodyReader::object($data, 'definition'),
		);
	}
}
