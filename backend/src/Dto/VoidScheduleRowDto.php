<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** A floor void in the room schedule (FD-90): listed with the rooms, its area (m²) taken off theirs and in no total. */
final readonly class VoidScheduleRowDto
{
	public function __construct(public int $id, public string $name, public float $area)
	{
	}

	public static function fromVoid(FloorVoidDto $void): self
	{
		return new self($void->id, $void->name, $void->area);
	}
}
