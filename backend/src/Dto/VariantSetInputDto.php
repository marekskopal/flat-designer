<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** A new variant set: its name and the groups that become its variants (the first is shown). */
final readonly class VariantSetInputDto implements ArrayFactoryInterface
{
	/** @param list<int> $groupIds */
	public function __construct(public string $name, public array $groupIds)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			name: BodyReader::string($data, 'name'),
			groupIds: ($data['groupIds'] ?? null) === null ? [] : BodyReader::intList($data, 'groupIds'),
		);
	}
}
