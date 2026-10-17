<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\ItemGroup;

/** An item group; its members are the items with this `groupId`. A variant of the set `variantSetId`. */
final readonly class GroupDto
{
	public function __construct(public int $id, public string $name, public ?int $variantSetId)
	{
	}

	public static function fromEntity(ItemGroup $group): self
	{
		$set = $group->variantSet;

		return new self(id: $group->id, name: $group->name, variantSetId: $set !== null && $set->deletedAt === null ? $set->id : null);
	}
}
