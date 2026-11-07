<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Model\Entity\Enum\ItemKindEnum;
use Izbik\Model\Entity\Item;
use Izbik\Service\Json\JsonDecoder;
use stdClass;

final readonly class ItemDto
{
	/** @param array<string, mixed>|stdClass $params */
	public function __construct(
		public int $id,
		public ?int $roomId,
		public ?int $groupId,
		public ItemKindEnum $kind,
		/** The furniture definition of a `Custom` item (in the scene's `definitions`). */
		public ?int $definitionId,
		/** The 3D model of a `Model` item (in the scene's `models`). */
		public ?int $modelId,
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
		public bool $onGround = false,
		/** `z` counts from the roof's top under the item's centre, and the item takes the plane's slope. */
		public bool $onRoof = false,
		/**
		 * A switch's lights: ids of items of the same floor, possibly deleted or in a hidden variant since (skip those).
		 *
		 * @var list<int>
		 */
		public array $controls = [],
	) {
	}

	public static function fromEntity(Item $item): self
	{
		$params = $item->params === null ? [] : JsonDecoder::decodeObject($item->params);

		return new self(
			id: $item->id,
			// A deleted room is not shown, so neither is the item's link to it (undo brings both back).
			roomId: $item->room !== null && $item->room->deletedAt === null ? $item->room->id : null,
			groupId: $item->group !== null && $item->group->deletedAt === null ? $item->group->id : null,
			kind: $item->kind,
			definitionId: $item->definition?->id,
			modelId: $item->model?->id,
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
			onGround: $item->onGround,
			onRoof: $item->onRoof,
			controls: self::controls($item->controls),
		);
	}

	/**
	 * A Custom item's params without an empty `values` or `materials`, as the editor stores them: decoded as PHP arrays,
	 * an empty JSON object would come back as `[]` (a list); a missing one means the same, the definition's defaults.
	 * Applied on write only, so items stored before keep their logged states (undo compares them).
	 *
	 * @param array<TKey, mixed> $params
	 * @return array<TKey, mixed>
	 * @template TKey of array-key
	 */
	public static function withoutEmptyMaps(array $params): array
	{
		foreach (['values', 'materials'] as $key) {
			if (array_key_exists($key, $params) && $params[$key] === []) {
				unset($params[$key]);
			}
		}

		return $params;
	}

	/** @return list<int> the ids of a stored `controls` list */
	public static function controls(?string $json): array
	{
		return $json === null ? [] : JsonDecoder::decodeIds($json);
	}
}
