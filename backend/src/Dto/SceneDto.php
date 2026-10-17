<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Everything the viewer needs to build one flat. All lengths are millimetres. */
final readonly class SceneDto
{
	/**
	 * @param list<MaterialDto> $materials
	 * @param list<RoomDto> $rooms
	 * @param list<WallDto> $walls
	 * @param list<ItemDto> $items
	 */
	public function __construct(
		public FlatDto $flat,
		public array $materials,
		public array $rooms,
		public array $walls,
		public array $items,
	)
	{
	}
}
