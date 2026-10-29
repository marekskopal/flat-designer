<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Everything the viewer needs to build one floor. All lengths are millimetres. */
final readonly class SceneDto
{
	/**
	 * @param list<MaterialDto> $materials
	 * @param list<RoomDto> $rooms
	 * @param list<PlotDto> $plots the land around the building
	 * @param list<WallDto> $walls
	 * @param list<ItemDto> $items
	 * @param list<GroupDto> $groups
	 * @param list<VariantSetDto> $variantSets
	 * @param list<SceneDefinitionDto> $definitions the furniture definitions of the Custom items
	 * @param list<SceneModelDto> $models the 3D models of the Model items
	 * @param list<StairDto> $stairs
	 * @param list<RoofDto> $roofs with their windows
	 * @param list<int> $pictures the project files the Custom items show as pictures (their `image` parameters)
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
		public array $definitions,
		/** The plan image laid under the top view for tracing. */
		public ?UnderlayDto $underlay = null,
		public array $models = [],
		public array $pictures = [],
		/** Where the project's building stands, for the sun. */
		public ?LocationDto $location = null,
		public array $plots = [],
		/** This floor's stairs, climbing `stairRise` mm to the next floor up (`StairRise`). */
		public array $stairs = [],
		public int $stairRise = 0,
		/** The floor below's stairs that come up through this floor and cut their voids through it. */
		public ?StairsBelowDto $stairsBelow = null,
		/** The roofs over this floor; their heights count from its level. */
		public array $roofs = [],
		/** The project's default façade material (FD-88). */
		public ?int $exteriorMaterialId = null,
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
