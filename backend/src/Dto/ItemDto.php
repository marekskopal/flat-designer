<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Service\Json\JsonDecoder;
use stdClass;

final readonly class ItemDto
{
	/** @param array<string, mixed>|stdClass $params */
	public function __construct(
		public int $id,
		public ?int $roomId,
		public ItemKindEnum $kind,
		public string $name,
		public int $x,
		public int $y,
		public int $z,
		public int $width,
		public int $depth,
		public int $height,
		public int $rotation,
		public ?int $materialId,
		public array|stdClass $params,
	) {
	}

	public static function fromEntity(Item $item): self
	{
		$params = $item->params === null ? [] : JsonDecoder::decodeObject($item->params);

		return new self(
			id: $item->id,
			// A deleted room is not shown, so neither is the item's link to it (undo brings both back).
			roomId: $item->room !== null && $item->room->deletedAt === null ? $item->room->id : null,
			kind: $item->kind,
			name: $item->name,
			x: $item->x,
			y: $item->y,
			z: $item->z,
			width: $item->width,
			depth: $item->depth,
			height: $item->height,
			rotation: $item->rotation,
			materialId: $item->material?->id,
			// An empty PHP array would encode as `[]`; keep params a JSON object for the client.
			params: $params === [] ? new stdClass() : $params,
		);
	}
}
