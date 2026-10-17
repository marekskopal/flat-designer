<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Everything the viewer needs to build one floor. All lengths are millimetres. */
final readonly class SceneDto
{
	/**
	 * @param list<MaterialDto> $materials
	 * @param list<RoomDto> $rooms
	 * @param list<WallDto> $walls
	 * @param list<ItemDto> $items
	 * @param list<GroupDto> $groups
	 * @param list<VariantSetDto> $variantSets
	 */
	public function __construct(
		public FloorDto $floor,
		/** The project's revision this scene was read at; edits send it back as their base. */
		public int $revision,
		public array $materials,
		public array $rooms,
		public array $walls,
		public array $items,
		public array $groups,
		public array $variantSets,
	)
	{
	}

	/** @return list<int> items not shown: members of a variant that is not its set's active one */
	public function hiddenItemIds(): array
	{
		$active = [];
		foreach ($this->variantSets as $set) {
			$active[$set->id] = $set->activeGroupId;
		}
		$hiddenGroups = [];
		foreach ($this->groups as $group) {
			if ($group->variantSetId !== null && ($active[$group->variantSetId] ?? null) !== $group->id) {
				$hiddenGroups[$group->id] = true;
			}
		}

		$hidden = [];
		foreach ($this->items as $item) {
			if ($item->groupId !== null && isset($hiddenGroups[$item->groupId])) {
				$hidden[] = $item->id;
			}
		}

		return $hidden;
	}
}
