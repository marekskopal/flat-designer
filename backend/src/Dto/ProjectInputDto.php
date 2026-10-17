<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** Body of project create and update; update replaces all fields. */
final readonly class ProjectInputDto implements ArrayFactoryInterface
{
	public function __construct(public string $name, public ?string $address, public ?string $description,)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(
			name: BodyReader::string($data, 'name'),
			address: BodyReader::optionalString($data, 'address'),
			description: BodyReader::optionalString($data, 'description'),
		);
	}
}
