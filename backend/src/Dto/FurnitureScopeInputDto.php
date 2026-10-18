<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Service\Request\BodyReader;
use FlatDesigner\Service\Request\Exception\InvalidRequestException;

/** Where a furniture definition goes (body of copy): `global`, `user` (the caller's library) or `project` with `projectId`. */
final readonly class FurnitureScopeInputDto implements ArrayFactoryInterface
{
	public function __construct(public FurnitureScopeEnum $scope, public ?int $projectId)
	{
	}

	public static function fromArray(array $data): static
	{
		$scope = self::readScope($data);
		if ($scope === null) {
			throw new InvalidRequestException('Field "scope" must be a string.');
		}

		return new self(scope: $scope, projectId: BodyReader::optionalInt($data, 'projectId'));
	}

	/** @param array<string, mixed> $data */
	public static function readScope(array $data): ?FurnitureScopeEnum
	{
		$value = BodyReader::optionalString($data, 'scope');
		if ($value === null) {
			return null;
		}

		return FurnitureScopeEnum::tryFrom($value) ?? throw new InvalidRequestException(
			'Field "scope" must be "global", "user" or "project".',
		);
	}
}
