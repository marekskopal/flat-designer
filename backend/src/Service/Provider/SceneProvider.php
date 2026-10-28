<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Provider;

use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Dto\GroupDto;
use FlatDesigner\Dto\ItemDto;
use FlatDesigner\Dto\LocationDto;
use FlatDesigner\Dto\MaterialDto;
use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Dto\PlotDto;
use FlatDesigner\Dto\RoofDto;
use FlatDesigner\Dto\RoofWindowDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\SceneDefinitionDto;
use FlatDesigner\Dto\SceneDto;
use FlatDesigner\Dto\SceneModelDto;
use FlatDesigner\Dto\StairDto;
use FlatDesigner\Dto\StairsBelowDto;
use FlatDesigner\Dto\UnderlayDto;
use FlatDesigner\Dto\VariantSetDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Model\Entity\Plot;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\Stair;
use FlatDesigner\Model\Entity\VariantSet;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\FloorUnderlayRepository;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\PlotRepository;
use FlatDesigner\Model\Repository\RoofRepository;
use FlatDesigner\Model\Repository\RoofWindowRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\StairRepository;
use FlatDesigner\Model\Repository\VariantSetRepository;
use FlatDesigner\Model\Repository\WallRepository;
use FlatDesigner\Service\Editor\StairRise;
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
		private FloorRepository $floorRepository,
		private PlotRepository $plotRepository,
		private StairRepository $stairRepository,
		private StairRise $stairRise,
		private ShownPictures $shownPictures,
		private RoofRepository $roofRepository,
		private RoofWindowRepository $roofWindowRepository,
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
		$floors = $this->floorRepository->findByProject($floor->project->id);

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
			plots: array_map(fn (Plot $plot): PlotDto => PlotDto::fromEntity($plot), $this->plotRepository->findByFloor($floor->id)),
			stairs: $this->stairs($floor),
			stairRise: $this->stairRise->of($floor, $floors, $walls),
			stairsBelow: $this->stairsBelow($floor, $floors),
			roofs: $this->roofs($floor),
		);
	}

	/** @return list<RoofDto> with their windows */
	private function roofs(Floor $floor): array
	{
		$roofs = $this->roofRepository->findByFloor($floor->id);
		$windowsByRoof = [];
		foreach ($this->roofWindowRepository->findByRoofs(array_map(fn (Roof $roof): int => $roof->id, $roofs)) as $window) {
			$windowsByRoof[$window->roof->id][] = RoofWindowDto::fromEntity($window);
		}

		return array_map(fn (Roof $roof): RoofDto => RoofDto::fromEntity($roof, $windowsByRoof[$roof->id] ?? []), $roofs);
	}

	/** @return list<StairDto> */
	private function stairs(Floor $floor): array
	{
		return array_map(fn (Stair $stair): StairDto => StairDto::fromEntity($stair), $this->stairRepository->findByFloor($floor->id));
	}

	/**
	 * The stairs of the floor below that come up through this one; null without a floor below.
	 *
	 * @param list<Floor> $floors the project's floors
	 */
	private function stairsBelow(Floor $floor, array $floors): ?StairsBelowDto
	{
		$below = $this->stairRise->floorBelow($floor, $floors);

		if ($below === null) {
			return null;
		}
		// A stair with a rise of its own (garden steps) stays on its floor.
		$through = array_values(array_filter($this->stairs($below), static fn (StairDto $stair): bool => $stair->rise === null));

		return new StairsBelowDto($below->id, $this->stairRise->of($below, $floors), $through);
	}

	/** @return list<MaterialDto> */
	public function getMaterials(): array
	{
		return array_map(fn (Material $m): MaterialDto => MaterialDto::fromEntity($m), $this->materialRepository->findAllOrdered());
	}
}
