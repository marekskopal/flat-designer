<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Floor;

/** A floor: its storey order (`level`), finished floor level above ±0.000 (`elevation`) and the slab under it, mm. */
final readonly class FloorDto
{
	public function __construct(public int $id, public string $name, public int $level, public int $elevation, public int $slabThickness)
	{
	}

	public static function fromEntity(Floor $floor): self
	{
		return new self(
			id: $floor->id,
			name: $floor->name,
			level: $floor->level,
			elevation: $floor->elevation,
			slabThickness: $floor->slabThickness,
		);
	}
}
