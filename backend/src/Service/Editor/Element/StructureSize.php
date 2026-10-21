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
		$soffit = $kind->isStructural() ? $this->ceilingSoffit($floor, $fields['roomId'] ?? null) : null;
		if ($soffit === null) {
			return [];
		}
		if ($kind === ItemKindEnum::Column) {
			return $given('height') === null && $given('z') === null ? ['height' => $soffit] : [];
		}

		return $given('z') === null ? ['z' => max(0, $soffit - ($given('height') ?? $defaultHeight))] : [];
	}

	/** Height of a room's ceiling, of a vaulted one at the springing; null without a room on the floor or a ceiling. */
	private function ceilingSoffit(Floor $floor, mixed $roomId): ?int
	{
		$room = is_int($roomId) ? $this->roomRepository->findOnFloor($floor->id, $roomId) : null;
		if ($room?->ceilingHeight === null) {
			return null;
		}

		return $room->vaultSpacing === null ? $room->ceilingHeight : $room->ceilingHeight - $room->vaultRise;
	}
}
