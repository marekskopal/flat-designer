<?php

declare(strict_types=1);

namespace Izbik\Service\Schedule;

use Izbik\Dto\CircuitItemDto;
use Izbik\Dto\ItemDto;
use Izbik\Dto\LightingCircuitDto;
use Izbik\Dto\LightingDto;
use Izbik\Dto\RoomDto;
use const PHP_INT_MAX;

/**
 * The lighting circuits of a floor: the connected parts of the graph of the switches' `controls` over the shown
 * switches and lights. A link to an item that is not shown (deleted, another variant) or no longer a light is
 * skipped. Circuits are numbered in the order of their first switch (room, name, id).
 */
final class LightingCircuits
{
	/** @var array<int, int> union-find parents by item id */
	private array $parent = [];

	/** @var array<int, true> the items with a link */
	private array $linked = [];

	/**
	 * @param array<int, CircuitItemDto> $entries the switches and lights by item id
	 * @param array<int, int> $roomOrder the order of the rooms by id
	 */
	private function __construct(private readonly array $entries, private readonly array $roomOrder)
	{
	}

	/**
	 * @param list<ItemDto> $switches the shown switch items
	 * @param list<ItemDto> $lights the shown light items
	 * @param list<RoomDto> $rooms the floor's rooms in the schedule's order
	 * @param array<int, int> $roomOfItem the switches' and lights' rooms by item id, 0 outside every room
	 */
	public static function of(array $switches, array $lights, array $rooms, array $roomOfItem): LightingDto
	{
		$roomOrder = [];
		$roomCodes = [];
		foreach ($rooms as $index => $room) {
			$roomOrder[$room->id] = $index;
			$roomCodes[$room->id] = $room->code;
		}
		$entries = [];
		foreach ([...$switches, ...$lights] as $item) {
			$roomId = $roomOfItem[$item->id] ?? 0;
			$entries[$item->id] = new CircuitItemDto(
				itemId: $item->id,
				name: $item->name,
				roomId: $roomId === 0 ? null : $roomId,
				roomCode: $roomCodes[$roomId] ?? null,
				z: $item->z,
			);
		}

		$circuits = new self($entries, $roomOrder);
		$circuits->link($switches, array_fill_keys(array_map(static fn (ItemDto $l): int => $l->id, $lights), true));

		return $circuits->result($switches, $lights);
	}

	/**
	 * @param list<ItemDto> $switches
	 * @param array<int, true> $isLight
	 */
	private function link(array $switches, array $isLight): void
	{
		foreach ($switches as $switch) {
			foreach (array_filter($switch->controls, static fn (int $id): bool => isset($isLight[$id])) as $lightId) {
				$this->linked[$switch->id] = true;
				$this->linked[$lightId] = true;
				$this->parent[$this->root($lightId)] = $this->root($switch->id);
			}
		}
	}

	/**
	 * @param list<ItemDto> $switches
	 * @param list<ItemDto> $lights
	 */
	private function result(array $switches, array $lights): LightingDto
	{
		/** @var array<int, list<CircuitItemDto>> $groupSwitches by root */
		$groupSwitches = [];
		/** @var array<int, list<CircuitItemDto>> $groupLights by root */
		$groupLights = [];
		foreach ($switches as $switch) {
			if (isset($this->linked[$switch->id])) {
				$groupSwitches[$this->root($switch->id)][] = $this->entries[$switch->id];
			}
		}
		foreach ($lights as $light) {
			if (isset($this->linked[$light->id])) {
				$groupLights[$this->root($light->id)][] = $this->entries[$light->id];
			}
		}

		$parts = [];
		foreach ($groupSwitches as $root => $members) {
			$parts[] = [$this->sorted($members), $this->sorted($groupLights[$root] ?? [])];
		}
		usort($parts, fn (array $a, array $b): int => $this->compare($a[0][0], $b[0][0]));

		return new LightingDto(
			circuits: array_map(
				static fn (array $part, int $i): LightingCircuitDto => new LightingCircuitDto($i + 1, $part[0], $part[1]),
				$parts,
				array_keys($parts),
			),
			unswitched: $this->unlinkedOf($lights),
			unlinked: $this->unlinkedOf($switches),
		);
	}

	/**
	 * @param list<ItemDto> $items
	 * @return list<CircuitItemDto>
	 */
	private function unlinkedOf(array $items): array
	{
		$unlinked = array_filter($items, fn (ItemDto $item): bool => !isset($this->linked[$item->id]));

		return $this->sorted(array_values(array_map(fn (ItemDto $item): CircuitItemDto => $this->entries[$item->id], $unlinked)));
	}

	private function root(int $id): int
	{
		while (($this->parent[$id] ?? $id) !== $id) {
			$id = $this->parent[$id];
		}

		return $id;
	}

	/**
	 * @param list<CircuitItemDto> $items
	 * @return list<CircuitItemDto>
	 */
	private function sorted(array $items): array
	{
		usort($items, $this->compare(...));

		return $items;
	}

	private function compare(CircuitItemDto $a, CircuitItemDto $b): int
	{
		$rank = fn (CircuitItemDto $item): int => $this->roomOrder[$item->roomId ?? 0] ?? PHP_INT_MAX;

		return [$rank($a), $a->name, $a->itemId] <=> [$rank($b), $b->name, $b->itemId];
	}
}
