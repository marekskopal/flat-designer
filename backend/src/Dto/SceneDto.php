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
	 */
	public function __construct(
		public FloorDto $floor,
		public array $materials,
		public array $rooms,
		public array $walls,
		public array $items,
	)
	{
	}
}
