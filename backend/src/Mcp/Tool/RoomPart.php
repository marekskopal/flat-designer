<?php

declare(strict_types=1);

namespace Izbik\Mcp\Tool;

use Izbik\Dto\GroupDto;
use Izbik\Dto\ItemDto;
use Izbik\Dto\RoomDto;
use Izbik\Dto\SceneDefinitionDto;
use Izbik\Dto\SceneDto;
use Izbik\Dto\SceneModelDto;
use Izbik\Dto\VariantSetDto;
use Izbik\Dto\WallDto;
use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Editor\Geometry\PlanMath;

/**
 * The part of a floor's scene `get_floor` answers: the whole floor, or one room with the walls around it (those whose
 * centre line comes within their thickness of the room's outline box), its items (and the items in no room standing in
 * its outline), their groups (a group's other members elsewhere are not listed), definitions and models, and the hidden
 * items and variant sets among them. A big floor does not fit an agent's tool result whole.
 */
final readonly class RoomPart
{
	/**
	 * @param list<RoomDto> $rooms
	 * @param list<WallDto> $walls
	 * @param list<ItemDto> $items
	 * @param list<GroupDto> $groups
	 * @param list<SceneDefinitionDto> $definitions
	 * @param list<SceneModelDto> $models
	 * @param list<VariantSetDto> $variantSets
	 * @param list<int> $hiddenItemIds
	 */
	private function __construct(
		public array $rooms,
		public array $walls,
		public array $items,
		public array $groups,
		public array $definitions,
		public array $models,
		public array $variantSets,
		public array $hiddenItemIds,
	) {
	}

	public static function whole(SceneDto $scene): self
	{
		return new self(
			$scene->rooms,
			$scene->walls,
			$scene->items,
			$scene->groups,
			$scene->definitions,
			$scene->models,
			$scene->variantSets,
			$scene->hiddenItemIds(),
		);
	}

	public static function of(SceneDto $scene, int $roomId): self
	{
		$room = array_find($scene->rooms, static fn (RoomDto $room): bool => $room->id === $roomId);
		if ($room === null) {
			throw EditException::notFound(sprintf('Room %d is not on this floor.', $roomId));
		}
		[$x0, $y0, $x1, $y1] = self::box($room);
		$outline = array_map(static fn (array $point): array => [(float) $point[0], (float) $point[1]], $room->polygon);

		$items = array_values(array_filter(
			$scene->items,
			static fn (ItemDto $item): bool => $item->roomId === $roomId
				|| ($item->roomId === null && PlanMath::contains($outline, [(float) $item->x, (float) $item->y])),
		));
		$groupIds = self::ids(array_map(static fn (ItemDto $item): ?int => $item->groupId, $items));
		$definitionIds = self::ids(array_map(static fn (ItemDto $item): ?int => $item->definitionId, $items));
		$modelIds = self::ids(array_map(static fn (ItemDto $item): ?int => $item->modelId, $items));
		$itemIds = self::ids(array_map(static fn (ItemDto $item): int => $item->id, $items));
		$groups = array_values(array_filter($scene->groups, static fn (GroupDto $group): bool => isset($groupIds[$group->id])));
		$setIds = self::ids(array_map(static fn (GroupDto $group): ?int => $group->variantSetId, $groups));

		return new self(
			[$room],
			array_values(array_filter($scene->walls, static fn (WallDto $wall): bool => self::near($wall, $x0, $y0, $x1, $y1))),
			$items,
			$groups,
			array_values(array_filter(
				$scene->definitions,
				static fn (SceneDefinitionDto $definition): bool => isset($definitionIds[$definition->id]),
			)),
			array_values(array_filter($scene->models, static fn (SceneModelDto $model): bool => isset($modelIds[$model->id]))),
			array_values(array_filter($scene->variantSets, static fn (VariantSetDto $set): bool => isset($setIds[$set->id]))),
			array_values(array_filter($scene->hiddenItemIds(), static fn (int $id): bool => isset($itemIds[$id]))),
		);
	}

	/** @return array{int, int, int, int} the room outline's box: x0, y0, x1, y1 */
	private static function box(RoomDto $room): array
	{
		$xs = array_column($room->polygon, 0);
		$ys = array_column($room->polygon, 1);

		return $xs === [] || $ys === [] ? [0, 0, 0, 0] : [min($xs), min($ys), max($xs), max($ys)];
	}

	/**
	 * @param list<int|null> $ids
	 * @return array<int, true> the ids given, as keys
	 */
	private static function ids(array $ids): array
	{
		$set = [];
		foreach ($ids as $id) {
			if ($id !== null) {
				$set[$id] = true;
			}
		}

		return $set;
	}

	/** Whether the wall's centre line (its sagitta allowed for) comes within its thickness of the box. */
	private static function near(WallDto $wall, int $x0, int $y0, int $x1, int $y1): bool
	{
		$margin = $wall->thickness + abs($wall->sagitta);

		return min($wall->startX, $wall->endX) - $margin <= $x1 && max($wall->startX, $wall->endX) + $margin >= $x0
			&& min($wall->startY, $wall->endY) - $margin <= $y1 && max($wall->startY, $wall->endY) + $margin >= $y0;
	}
}
