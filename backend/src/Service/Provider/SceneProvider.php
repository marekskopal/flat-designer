<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Provider;

use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Dto\GroupDto;
use FlatDesigner\Dto\ItemDto;
use FlatDesigner\Dto\LocationDto;
use FlatDesigner\Dto\MaterialDto;
use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\SceneDefinitionDto;
use FlatDesigner\Dto\SceneDto;
use FlatDesigner\Dto\SceneModelDto;
use FlatDesigner\Dto\UnderlayDto;
use FlatDesigner\Dto\VariantSetDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\VariantSet;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FloorUnderlayRepository;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\VariantSetRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Project\ShownPictures;

/** Builds the viewer's scene of one floor of the working copy. */
final readonly class SceneProvider
{
	public function __construct(
		private MaterialRepository $materialRepository,
		private RoomRepository $roomRepository,
		private WallRepository $wallRepository,
		private OpeningRepository $openingRepository,
		private ItemRepository $itemRepository,
		private ItemGroupRepository $groupRepository,
		private VariantSetRepository $variantSetRepository,
		private FloorUnderlayRepository $underlayRepository,
		private ShownPictures $shownPictures,
	) {
	}

	public function getScene(Floor $floor): SceneDto
	{
		$walls = $this->wallRepository->findByFloor($floor->id);

		$openingsByWall = [];
		foreach ($this->openingRepository->findByWalls(array_map(fn (Wall $wall): int => $wall->id, $walls)) as $opening) {
			$openingsByWall[$opening->wall->id][] = OpeningDto::fromEntity($opening);
		}

		$groups = $this->groupRepository->findByFloor($floor->id);
		$groupsById = [];
		foreach ($groups as $group) {
			$groupsById[$group->id] = $group;
		}

		$items = $this->itemRepository->findByFloor($floor->id);
		$definitions = [];
		$models = [];
		foreach ($items as $item) {
			if ($item->definition !== null) {
				$definitions[$item->definition->id] = $item->definition;
			}
			if ($item->model !== null) {
				$models[$item->model->id] = $item->model;
			}
		}
		ksort($definitions);
		ksort($models);
		$underlay = $this->underlayRepository->findByFloor($floor->id);

		return new SceneDto(
			floor: FloorDto::fromEntity($floor),
			revision: $floor->project->revision,
			materials: $this->getMaterials(),
			rooms: array_map(fn (Room $room): RoomDto => RoomDto::fromEntity($room), $this->roomRepository->findByFloor($floor->id)),
			walls: array_map(fn (Wall $wall): WallDto => WallDto::fromEntity($wall, $openingsByWall[$wall->id] ?? []), $walls),
			items: array_map(fn (Item $item): ItemDto => ItemDto::fromEntity($item), $items),
			groups: array_map(fn (ItemGroup $group): GroupDto => GroupDto::fromEntity($group), $groups),
			variantSets: array_map(
				fn (VariantSet $set): VariantSetDto => VariantSetDto::fromEntity($set, $groupsById[$set->activeGroupId ?? 0] ?? null),
				$this->variantSetRepository->findByFloor($floor->id),
			),
			definitions: array_map(
				fn (FurnitureDefinition $definition): SceneDefinitionDto => SceneDefinitionDto::fromEntity($definition),
				array_values($definitions),
			),
			underlay: $underlay === null ? null : UnderlayDto::fromEntity($underlay),
			models: array_map(fn (ModelAsset $model): SceneModelDto => SceneModelDto::fromEntity($model), array_values($models)),
			pictures: $this->shownPictures->byItems($floor->project, $items),
			location: LocationDto::fromEntity($floor->project),
		);
	}

	/** @return list<MaterialDto> */
	public function getMaterials(): array
	{
		return array_map(fn (Material $m): MaterialDto => MaterialDto::fromEntity($m), $this->materialRepository->findAllOrdered());
	}
}
