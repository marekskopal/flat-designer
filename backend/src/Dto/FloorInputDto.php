<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** Body of floor create and update; update replaces all fields. */
final readonly class FloorInputDto implements ArrayFactoryInterface
{
	public function __construct(public string $name, public int $level)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(name: BodyReader::string($data, 'name'), level: BodyReader::int($data, 'level'));
	}
}
