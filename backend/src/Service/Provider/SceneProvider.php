<?php

declare(strict_types=1);

namespace Izbik\Service\Provider;

use Izbik\Dto\ChimneyFloorDto;
use Izbik\Dto\FloorDto;
use Izbik\Dto\FloorVoidDto;
use Izbik\Dto\GroupDto;
use Izbik\Dto\ItemDto;
use Izbik\Dto\LocationDto;
use Izbik\Dto\MaterialDto;
use Izbik\Dto\OpeningDto;
use Izbik\Dto\PlotDto;
use Izbik\Dto\RailingDto;
use Izbik\Dto\RoofDto;
use Izbik\Dto\RoofWindowDto;
use Izbik\Dto\RoomDto;
use Izbik\Dto\SceneDefinitionDto;
use Izbik\Dto\SceneDto;
use Izbik\Dto\SceneModelDto;
use Izbik\Dto\SectionLineDto;
use Izbik\Dto\StairDto;
use Izbik\Dto\StairsBelowDto;
use Izbik\Dto\UnderlayDto;
use Izbik\Dto\VariantSetDto;
use Izbik\Dto\VoidsAboveDto;
use Izbik\Dto\WallDto;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\FloorVoid;
use Izbik\Model\Entity\FurnitureDefinition;
use Izbik\Model\Entity\Item;
use Izbik\Model\Entity\ItemGroup;
use Izbik\Model\Entity\Material;
use Izbik\Model\Entity\ModelAsset;
use Izbik\Model\Entity\Plot;
use Izbik\Model\Entity\Railing;
use Izbik\Model\Entity\Roof;
use Izbik\Model\Entity\Room;
use Izbik\Model\Entity\SectionLine;
use Izbik\Model\Entity\Stair;
use Izbik\Model\Entity\VariantSet;
use Izbik\Model\Entity\Wall;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Model\Repository\FloorUnderlayRepository;
use Izbik\Model\Repository\FloorVoidRepository;
use Izbik\Model\Repository\ItemGroupRepository;
use Izbik\Model\Repository\ItemRepository;
use Izbik\Model\Repository\MaterialRepository;
use Izbik\Model\Repository\OpeningRepository;
use Izbik\Model\Repository\PlotRepository;
use Izbik\Model\Repository\RailingRepository;
use Izbik\Model\Repository\RoofRepository;
use Izbik\Model\Repository\RoofWindowRepository;
use Izbik\Model\Repository\RoomRepository;
use Izbik\Model\Repository\SectionLineRepository;
use Izbik\Model\Repository\StairRepository;
use Izbik\Model\Repository\VariantSetRepository;
use Izbik\Model\Repository\WallRepository;
use Izbik\Service\Editor\StairRise;
use Izbik\Service\Project\ShownPictures;

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
		private FloorVoidRepository $voidRepository,
		private RailingRepository $railingRepository,
		private SectionLineRepository $sectionLineRepository,
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
			exteriorMaterialId: $floor->project->exteriorMaterial?->id,
			voids: $this->voids($floor),
			railings: array_map(
				fn (Railing $r): RailingDto => RailingDto::fromEntity($r),
				$this->railingRepository->findByFloor($floor->id),
			),
			voidsAbove: $this->voidsAbove($floor, $floors, $walls),
			chimneyFloors: $this->chimneyFloors($floor, $floors),
			sections: array_map(
				fn (SectionLine $line): SectionLineDto => SectionLineDto::fromEntity($line),
				$this->sectionLineRepository->findByFloor($floor->id),
			),
		);
	}

	/**
	 * The other floors with roofs or chimneys, their levels against this one's; none in a project without chimneys.
	 *
	 * @param list<Floor> $floors the project's floors
	 * @return list<ChimneyFloorDto>
	 */
	private function chimneyFloors(Floor $floor, array $floors): array
	{
		$ids = array_map(fn (Floor $f): int => $f->id, $floors);
		$chimneys = [];
		foreach ($this->itemRepository->findChimneysOnFloors($ids) as $chimney) {
			$chimneys[$chimney->floor->id][] = ItemDto::fromEntity($chimney);
		}
		if ($chimneys === []) {
			return [];
		}
		$roofs = [];
		foreach ($this->roofRepository->findByFloors($ids) as $roof) {
			$roofs[$roof->floor->id][] = RoofDto::fromEntity($roof, []);
		}
		$result = [];
		foreach ($floors as $other) {
			if ($other->id !== $floor->id && (isset($roofs[$other->id]) || isset($chimneys[$other->id]))) {
				$result[] = new ChimneyFloorDto(
					$other->id,
					$other->elevation - $floor->elevation,
					$roofs[$other->id] ?? [],
					$chimneys[$other->id] ?? [],
				);
			}
		}

		return $result;
	}

	/** @return list<FloorVoidDto> */
	private function voids(Floor $floor): array
	{
		return array_map(
			fn (FloorVoid $void): FloorVoidDto => FloorVoidDto::fromEntity($void),
			$this->voidRepository->findByFloor($floor->id),
		);
	}

	/**
	 * The floor this floor's stairs climb to, with its slab and voids; null without one above.
	 *
	 * @param list<Floor> $floors the project's floors
	 * @param list<Wall> $walls the floor's
	 */
	private function voidsAbove(Floor $floor, array $floors, array $walls): ?VoidsAboveDto
	{
		$above = StairRise::above($floor->level, StairRise::levels($floors));
		foreach ($floors as $other) {
			if ($above !== null && $other->id === $above['id']) {
				return new VoidsAboveDto(
					$other->id,
					$this->stairRise->of($floor, $floors, $walls),
					$other->slabThickness,
					$this->voids($other),
				);
			}
		}

		return null;
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
