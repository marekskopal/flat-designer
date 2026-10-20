<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;

/**
 * A model's describing fields (the file comes separately): name, description and the credits of a third-party model.
 * `scope` and `projectId` only on upload (a model is not moved later).
 */
final readonly class ModelAssetInputDto implements ArrayFactoryInterface
{
	public function __construct(
		public string $name,
		public string $description = '',
		public string $license = '',
		public string $author = '',
		public string $sourceUrl = '',
		public ?FurnitureScopeEnum $scope = null,
		public ?int $projectId = null,
	) {
	}

	public static function fromArray(array $data): static
	{
		$scope = BodyReader::optionalString($data, 'scope');
		$rawProjectId = $data['projectId'] ?? null;
		// Multipart fields come as strings.
		$projectId = match (true) {
			$rawProjectId === null, $rawProjectId === '' => null,
			is_int($rawProjectId) => $rawProjectId,
			is_string($rawProjectId) && ctype_digit($rawProjectId) => (int) $rawProjectId,
			default => throw new InvalidRequestException('Field "projectId" must be an integer.'),
		};

		return new self(
			name: BodyReader::string($data, 'name'),
			description: BodyReader::optionalString($data, 'description') ?? '',
			license: BodyReader::optionalString($data, 'license') ?? '',
			author: BodyReader::optionalString($data, 'author') ?? '',
			sourceUrl: BodyReader::optionalString($data, 'sourceUrl') ?? '',
			scope: $scope === null ? null : (FurnitureScopeEnum::tryFrom($scope) ?? throw new InvalidRequestException(
				'Field "scope" must be "global", "user" or "project".',
			)),
			projectId: $projectId,
		);
	}
}
