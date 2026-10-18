<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Request\BodyReader;

/** Body of a furniture definition check: the definition only. */
final readonly class FurnitureValidationInputDto implements ArrayFactoryInterface
{
	/** @param array<string, mixed> $definition */
	public function __construct(public array $definition)
	{
	}

	public static function fromArray(array $data): static
	{
		return new self(definition: BodyReader::object($data, 'definition'));
	}
}
