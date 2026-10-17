<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\VariantSet;

/** A variant set; its variants are the groups with this `variantSetId`, of which `activeGroupId` is shown. */
final readonly class VariantSetDto
{
	public function __construct(public int $id, public string $name, public ?int $activeGroupId)
	{
	}

	/** @param ItemGroup|null $activeGroup the group `activeGroupId` refers to */
	public static function fromEntity(VariantSet $set, ?ItemGroup $activeGroup): self
	{
		// A deleted group, or one that left the set, is not active (undo of its deletion brings it back).
		$active = $activeGroup !== null && $activeGroup->deletedAt === null && $activeGroup->variantSet?->id === $set->id;

		return new self(id: $set->id, name: $set->name, activeGroupId: $active ? $activeGroup->id : null);
	}
}
