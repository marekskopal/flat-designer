<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Model\Repository\RoofRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Service\Editor\Geometry\RoofGeometry;

/**
 * The size a new column or beam starts with in a room: a column reaches the room's ceiling, a beam hangs from it (a
 * chimney stands on its floor). A
 * vaulted ceiling counts at its springing, where its own beams start; under a roof sloping lower than the ceiling, the
 * roof's underside at the item's footprint centre counts (FD-38), unless it comes down to the room's floor (an
 * overhang beside a raised room), where there is no room for a column anyway. A height or elevation the client gives wins.
 */
final readonly class StructureSize
{
	/** mm, the least a roof's underside is above the room's floor to count. */
	private const int MinUnderRoof = 100;

	public function __construct(private RoomRepository $roomRepository, private RoofRepository $roofRepository)
	{
	}

	/** The roofs' underside over the point, mm above the room's floor; null without a roof there, or with one down at the floor. */
	private function underRoof(Floor $floor, ?int $x, ?int $y, int $floorLevel): ?int
	{
		if ($x === null || $y === null) {
			return null;
		}
		$roofs = array_map(fn (Roof $roof): RoofGeometry => RoofGeometry::fromRoof($roof), $this->roofRepository->findByFloor($floor->id));
		$underside = RoofGeometry::undersideOf($roofs, $x, $y);

		return $underside === null || $underside - $floorLevel < self::MinUnderRoof ? null : (int) floor($underside) - $floorLevel;
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
		$soffit = min($soffit, $this->underRoof($floor, $given('x'), $given('y'), $floorLevel) ?? $soffit);
		if ($kind === ItemKindEnum::Column) {
			return $given('height') === null && $given('z') === null ? ['height' => $soffit, 'z' => $floorLevel] : [];
		}
		// A chimney rises through the roofs above, whatever the ceiling: only its foot is the room's floor.
		if ($kind === ItemKindEnum::Chimney) {
			return $given('z') === null ? ['z' => $floorLevel] : [];
		}

		return $given('z') === null ? ['z' => max(0, $floorLevel + $soffit - ($given('height') ?? $defaultHeight))] : [];
	}
}
