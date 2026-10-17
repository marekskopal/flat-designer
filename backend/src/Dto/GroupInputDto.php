<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** A new item group: its name and the items to put in it (may be none). */
final readonly class GroupInputDto implements ArrayFactoryInterface
{
	/** @param list<int> $itemIds */
	public function __construct(public string $name, public array $itemIds)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			name: BodyReader::string($data, 'name'),
			itemIds: ($data['itemIds'] ?? null) === null ? [] : BodyReader::intList($data, 'itemIds'),
		);
	}
}
