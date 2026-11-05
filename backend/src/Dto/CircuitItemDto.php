<?php

declare(strict_types=1);

namespace Izbik\Dto;

/** A switch or light of the lighting circuits, with its room (null outside every room) and elevation (mm, its bottom edge). */
final readonly class CircuitItemDto
{
	public function __construct(public int $itemId, public string $name, public ?int $roomId, public ?string $roomCode, public int $z,)
	{
	}
}
