<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** A new variant of a set: a copy of the variant `fromGroupId`, or empty; `groupId` alone switches to a variant. */
final readonly class VariantInputDto implements ArrayFactoryInterface
{
	public function __construct(public ?int $fromGroupId, public ?string $name, public ?int $groupId)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			fromGroupId: BodyReader::optionalInt($data, 'fromGroupId'),
			name: BodyReader::optionalString($data, 'name'),
			groupId: BodyReader::optionalInt($data, 'groupId'),
		);
	}
}
