<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Repository\RoomRepository;

/**
 * The size a new column or beam starts with in a room: a column reaches the room's ceiling, a beam hangs from it. A
 * vaulted ceiling counts at its springing, where its own beams start. A height or elevation the client gives wins.
 */
final readonly class StructureSize
{
	public function __construct(private RoomRepository $roomRepository)
	{
	}

	/**
	 * @param array<string, mixed> $fields the client's fields
	 * @return array{height?: int, z?: int} what differs from the kind's default size, of `defaultHeight` high
	 */
	public function fit(Floor $floor, ItemKindEnum $kind, array $fields, int $defaultHeight): array
	{
		$given = static fn (string $key): ?int => is_int($fields[$key] ?? null) ? $fields[$key] : null;
		$room = $kind->isStructural() && is_int($fields['roomId'] ?? null)
			? $this->roomRepository->findOnFloor($floor->id, $fields['roomId'])
			: null;
		if ($room?->ceilingHeight === null) {
			return [];
		}
		// The ceiling counts from the room's floor; an item's z from the floor's level.
		$soffit = $room->vaultSpacing === null ? $room->ceilingHeight : $room->ceilingHeight - $room->vaultRise;
		$floorLevel = $room->floorElevation;
		if ($kind === ItemKindEnum::Column) {
			return $given('height') === null && $given('z') === null ? ['height' => $soffit, 'z' => $floorLevel] : [];
		}

		return $given('z') === null ? ['z' => max(0, $floorLevel + $soffit - ($given('height') ?? $defaultHeight))] : [];
	}
}
