<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Provider;

use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Dto\ItemDto;
use FlatDesigner\Dto\MaterialDto;
use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\SceneDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\WallRepository;

/** Builds the viewer's scene of one floor of the working copy. */
final readonly class SceneProvider
{
	public function __construct(
		private MaterialRepository $materialRepository,
		private RoomRepository $roomRepository,
		private WallRepository $wallRepository,
		private OpeningRepository $openingRepository,
		private ItemRepository $itemRepository,
	) {
	}

	public function getScene(Floor $floor): SceneDto
	{
		$walls = $this->wallRepository->findByFloor($floor->id);

		$openingsByWall = [];
		foreach ($this->openingRepository->findByWalls(array_map(fn (Wall $wall): int => $wall->id, $walls)) as $opening) {
			$openingsByWall[$opening->wall->id][] = OpeningDto::fromEntity($opening);
		}

		return new SceneDto(
			floor: FloorDto::fromEntity($floor),
			revision: $floor->project->revision,
			materials: $this->getMaterials(),
			rooms: array_map(fn (Room $room): RoomDto => RoomDto::fromEntity($room), $this->roomRepository->findByFloor($floor->id)),
			walls: array_map(fn (Wall $wall): WallDto => WallDto::fromEntity($wall, $openingsByWall[$wall->id] ?? []), $walls),
			items: array_map(fn (Item $item): ItemDto => ItemDto::fromEntity($item), $this->itemRepository->findByFloor($floor->id)),
		);
	}

	/** @return list<MaterialDto> */
	public function getMaterials(): array
	{
		return array_map(fn (Material $m): MaterialDto => MaterialDto::fromEntity($m), $this->materialRepository->findAllOrdered());
	}
}
