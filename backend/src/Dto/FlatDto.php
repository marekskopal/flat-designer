<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Flat;

final readonly class FlatDto
{
	public function __construct(public int $id, public string $name, public ?string $address, public ?string $description,)
	{
	}

	public static function fromEntity(Flat $flat): self
	{
		return new self(id: $flat->id, name: $flat->name, address: $flat->address, description: $flat->description);
	}
}
