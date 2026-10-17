<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

final readonly class CurrentUserUpdateDto implements ArrayFactoryInterface
{
	public function __construct(public ?string $name)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(name: BodyReader::optionalString($data, 'name'));
	}
}
