<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

final readonly class ProjectVersionInputDto implements ArrayFactoryInterface
{
	public function __construct(public ?string $note)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(note: BodyReader::optionalString($data, 'note'));
	}
}
