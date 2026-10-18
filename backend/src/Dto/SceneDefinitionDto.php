<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\FurnitureDefinition;
use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

/**
 * A furniture definition as the viewer needs it to draw the scene's `Custom` items; `updatedAt` tells the viewer when to
 * rebuild them. Deleted definitions still in use are included.
 */
final readonly class SceneDefinitionDto
{
	public function __construct(public int $id, public string $name, public mixed $definition, public string $updatedAt)
	{
	}

	public static function fromEntity(FurnitureDefinition $definition): self
	{
		return new self(
			id: $definition->id,
			name: $definition->name,
			// Decoded to objects, so empty ones stay `{}`.
			definition: json_decode($definition->definition, flags: JSON_THROW_ON_ERROR),
			updatedAt: $definition->updatedAt->format(DATE_ATOM),
		);
	}
}
