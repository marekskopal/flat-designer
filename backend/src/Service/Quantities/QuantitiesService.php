<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Quantities;

use FlatDesigner\Dto\QuantitiesDto;
use FlatDesigner\Dto\QuantityLineDto;
use FlatDesigner\Dto\QuantityMaterialDto;
use FlatDesigner\Dto\QuantityRoofDto;
use FlatDesigner\Dto\QuantityRoomDto;
use FlatDesigner\Dto\RoofDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Enum\QuantitySurfaceEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Service\Editor\Geometry\RoofCover;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;
use FlatDesigner\Service\Furniture\CutList\CutListService;
use FlatDesigner\Service\Provider\SceneProvider;

/**
 * Quantities and cost estimate of a project or a floor (working copy): the rooms' surfaces (`RoomSurfaces`, under the
 * floor's roofs), the roofs' covering and soffit (`RoofSurfaces`), the boards
 * of the Custom furniture (`CutListService`), and their cost from the project's prices (`MaterialPriceService`,
 * `Costing`). Room costs count whole pieces per room, the material totals whole pieces for all rooms together.
 */
final readonly class QuantitiesService
{
	public function __construct(
		private SceneProvider $sceneProvider,
		private FloorRepository $floorRepository,
		private CutListService $cutListService,
		private MaterialPriceService $priceService,
	) {
	}

	public function forProject(Project $project): QuantitiesDto
	{
		return $this->quantities($project, $this->floorRepository->findByProject($project->id));
	}

	public function forFloor(Floor $floor): QuantitiesDto
	{
		return $this->quantities($floor->project, [$floor]);
	}

	/** @param list<Floor> $floors */
	private function quantities(Project $project, array $floors): QuantitiesDto
	{
		$materials = [];
		foreach ($this->sceneProvider->getMaterials() as $material) {
			$materials[$material->id] = $material;
		}
		$costing = new Costing(PriceList::of($this->priceService->get($project)->prices), $materials, $project->wastePercent / 100);
		// Each floor's scene is built once, for the rooms and for the cut list.
		$scenes = array_map(fn (Floor $floor): array => [$floor, $this->sceneProvider->getScene($floor)], $floors);
		$cutList = $this->cutListService->forScenes($scenes);

		$rooms = [];
		$roofs = [];
		foreach ($scenes as [$floor, $scene]) {
			$cover = new RoofCover(array_map(static fn (RoofDto $roof): RoofGeometry => RoofGeometry::fromDto($roof), $scene->roofs));
			$floorRooms = array_map(
				fn (RoomDto $room): QuantityRoomDto => $this->room($costing, $floor, $room, $scene->walls, $cover),
				$scene->rooms,
			);
			usort($floorRooms, static fn (QuantityRoomDto $x, QuantityRoomDto $y): int => strnatcmp($x->code, $y->code));
			array_push($rooms, ...$floorRooms);
			foreach (RoofSurfaces::of($scene->roofs, $materials, $cover) as $roof) {
				$roofs[] = self::roof($costing, $floor, $roof);
			}
		}

		$materialTotals = self::totals($costing, [...$rooms, ...$roofs]);
		$furniture = array_map($costing->boards(...), $cutList->materials);
		$all = [...$materialTotals, ...$furniture];

		return new QuantitiesDto(
			currency: $project->currency,
			wastePercent: $project->wastePercent,
			rooms: $rooms,
			materials: $materialTotals,
			furniture: $furniture,
			total: round(array_sum(array_map(static fn (QuantityMaterialDto $m): float => $m->cost ?? 0.0, $all)), 2),
			complete: array_all($all, static fn (QuantityMaterialDto $m): bool => $m->cost !== null),
			roofs: $roofs,
		);
	}

	/** @param list<WallDto> $walls */
	private function room(Costing $costing, Floor $floor, RoomDto $room, array $walls, RoofCover $roofs): QuantityRoomDto
	{
		$lines = self::sorted(array_map($costing->line(...), RoomSurfaces::of($room, $walls, $roofs)));

		return new QuantityRoomDto(
			id: $room->id,
			code: $room->code,
			name: $room->name,
			floorId: $floor->id,
			floorName: $floor->name,
			lines: $lines,
			cost: round(array_sum(array_map(static fn (QuantityLineDto $line): float => $line->cost ?? 0.0, $lines)), 2),
			complete: array_all($lines, static fn (QuantityLineDto $line): bool => $line->cost !== null),
		);
	}

	/** @param array{roof: RoofDto, windows: int, surfaces: list<array{surface: QuantitySurfaceEnum, materialId: int|null, amount: float}>} $roof */
	private static function roof(Costing $costing, Floor $floor, array $roof): QuantityRoofDto
	{
		$lines = self::sorted(array_map($costing->line(...), array_values(array_filter(
			$roof['surfaces'],
			static fn (array $surface): bool => $surface['amount'] > 0,
		))));

		return new QuantityRoofDto(
			id: $roof['roof']->id,
			name: $roof['roof']->name,
			floorId: $floor->id,
			floorName: $floor->name,
			lines: $lines,
			windows: $roof['windows'],
			cost: round(array_sum(array_map(static fn (QuantityLineDto $line): float => $line->cost ?? 0.0, $lines)), 2),
			complete: array_all($lines, static fn (QuantityLineDto $line): bool => $line->cost !== null),
		);
	}

	/**
	 * By surface, then material.
	 *
	 * @param list<QuantityLineDto> $lines
	 * @return list<QuantityLineDto>
	 */
	private static function sorted(array $lines): array
	{
		usort($lines, static function (QuantityLineDto $x, QuantityLineDto $y): int {
			$bySurface = self::surfaceOrder($x->surface) <=> self::surfaceOrder($y->surface);

			return $bySurface !== 0 ? $bySurface : strcmp($x->material, $y->material);
		});

		return $lines;
	}

	/**
	 * One total per material over all rooms and roofs; without a material, one per surface (each has its own default
	 * look).
	 *
	 * @param list<QuantityRoomDto|QuantityRoofDto> $rooms
	 * @return list<QuantityMaterialDto> by name
	 */
	private static function totals(Costing $costing, array $rooms): array
	{
		/** @var array<string, array{surface: QuantitySurfaceEnum, materialId: int|null, surfaces: array<string, QuantitySurfaceEnum>, area: float, length: float}> $sums */
		$sums = [];
		foreach ($rooms as $room) {
			foreach ($room->lines as $line) {
				$key = PriceList::key($line->materialId, $line->surface);
				$sums[$key] ??= ['surface' => $line->surface, 'materialId' => $line->materialId, 'surfaces' => [], 'area' => 0.0, 'length' => 0.0];
				$sums[$key]['surfaces'][$line->surface->value] = $line->surface;
				$sums[$key]['area'] += $line->area ?? 0.0;
				$sums[$key]['length'] += $line->length ?? 0.0;
			}
		}

		$totals = [];
		foreach ($sums as $sum) {
			$surfaces = array_values($sum['surfaces']);
			usort(
				$surfaces,
				static fn (QuantitySurfaceEnum $x, QuantitySurfaceEnum $y): int => self::surfaceOrder($x) <=> self::surfaceOrder($y),
			);
			$totals[] = $costing->total($costing->material($sum['materialId']), $sum['surface'], $surfaces, $sum['area'], $sum['length']);
		}
		usort($totals, static fn (QuantityMaterialDto $x, QuantityMaterialDto $y): int => strcmp($x->material, $y->material));

		return $totals;
	}

	private static function surfaceOrder(QuantitySurfaceEnum $surface): int
	{
		return (int) array_search($surface, QuantitySurfaceEnum::cases(), true);
	}
}
