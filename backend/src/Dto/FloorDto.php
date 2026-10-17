<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Floor;

final readonly class FloorDto
{
	public function __construct(public int $id, public string $name, public int $level)
	{
	}

	public static function fromEntity(Floor $floor): self
	{
		return new self(id: $floor->id, name: $floor->name, level: $floor->level);
	}
}
