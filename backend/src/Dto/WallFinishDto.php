<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Horizontal band of a wall finish (e.g. tiling) around a room, heights in mm above the floor. */
final readonly class WallFinishDto
{
	public function __construct(public int $materialId, public int $bottom, public int $top,)
	{
	}
}
