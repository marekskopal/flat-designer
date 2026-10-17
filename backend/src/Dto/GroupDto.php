<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\ItemGroup;

/** An item group; its members are the items with this `groupId`. */
final readonly class GroupDto
{
	public function __construct(public int $id, public string $name)
	{
	}

	public static function fromEntity(ItemGroup $group): self
	{
		return new self(id: $group->id, name: $group->name);
	}
}
