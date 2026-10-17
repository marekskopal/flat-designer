<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Provider;

use FlatDesigner\Dto\FlatDto;
use FlatDesigner\Dto\ItemDto;
use FlatDesigner\Dto\MaterialDto;
use FlatDesigner\Dto\OpeningDto;
use FlatDesigner\Dto\RoomDto;
use FlatDesigner\Dto\SceneDto;
use FlatDesigner\Dto\WallDto;
use FlatDesigner\Model\Entity\Flat;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\Material;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Entity\Wall;
use FlatDesigner\Model\Repository\FlatRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\MaterialRepository;
use FlatDesigner\Model\Repository\OpeningRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Model\Repository\WallRepository;

final readonly class SceneProvider
{
	public function __construct(
		private FlatRepository $flatRepository,
		private MaterialRepository $materialRepository,
		private RoomRepository $roomRepository,
		private WallRepository $wallRepository,
		private OpeningRepository $openingRepository,
		private ItemRepository $itemRepository,
	) {
	}

	/** @return list<FlatDto> */
	public function getFlats(): array
	{
		return array_map(fn (Flat $flat): FlatDto => FlatDto::fromEntity($flat), $this->flatRepository->findAllOrdered());
	}

	public function getScene(int $flatId): ?SceneDto
	{
		$flat = $this->flatRepository->findById($flatId);
		if ($flat === null) {
			return null;
		}

		$walls = $this->wallRepository->findByFlat($flat->id);

		$openingsByWall = [];
		foreach ($this->openingRepository->findByWalls(array_map(fn (Wall $wall): int => $wall->id, $walls)) as $opening) {
			$openingsByWall[$opening->wall->id][] = OpeningDto::fromEntity($opening);
		}

		return new SceneDto(
			flat: FlatDto::fromEntity($flat),
			materials: array_map(fn (Material $m): MaterialDto => MaterialDto::fromEntity($m), $this->materialRepository->findAllOrdered()),
			rooms: array_map(fn (Room $room): RoomDto => RoomDto::fromEntity($room), $this->roomRepository->findByFlat($flat->id)),
			walls: array_map(fn (Wall $wall): WallDto => WallDto::fromEntity($wall, $openingsByWall[$wall->id] ?? []), $walls),
			items: array_map(fn (Item $item): ItemDto => ItemDto::fromEntity($item), $this->itemRepository->findByFlat($flat->id)),
		);
	}
}
