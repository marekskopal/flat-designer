<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Repository\RoomRepository;

/**
 * The size a new column or beam starts with in a room: a column reaches the room's ceiling, a beam hangs from it. A
 * vaulted ceiling counts at its springing, where its own beams start.
 */
final readonly class StructureSize
{
	public function __construct(private RoomRepository $roomRepository)
	{
	}

	/** @return array{height?: int, z?: int} what differs from the kind's default size, of `height` high */
	public function fit(ItemKindEnum $kind, mixed $roomId, int $height): array
	{
		$soffit = $kind->isStructural() ? $this->ceilingSoffit($roomId) : null;
		if ($soffit === null) {
			return [];
		}

		return $kind === ItemKindEnum::Column ? ['height' => $soffit] : ['z' => max(0, $soffit - $height)];
	}

	/** Height of a room's ceiling, of a vaulted one at the springing; null without a room or a ceiling. */
	private function ceilingSoffit(mixed $roomId): ?int
	{
		$room = is_int($roomId) ? $this->roomRepository->findOne(['id' => $roomId]) : null;
		if ($room?->ceilingHeight === null) {
			return null;
		}

		return $room->vaultSpacing === null ? $room->ceilingHeight : $room->ceilingHeight - $room->vaultRise;
	}
}
