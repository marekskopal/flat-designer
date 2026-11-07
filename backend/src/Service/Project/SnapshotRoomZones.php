<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

use Izbik\Model\Entity\Material;
use Izbik\Service\Editor\Element\RoomZones;

/**
 * A room's floor and ceiling zones from a snapshot (`RoomZones`), as stored: a floor zone whose material this instance no
 * longer has is left out, a ceiling zone's falls back to the ceiling's. Snapshots taken before the zones have none.
 */
final readonly class SnapshotRoomZones
{
	/** @param array<int, Material> $materials */
	public static function floor(SnapshotReader $data, array $materials): ?string
	{
		return RoomZones::encode(array_values(array_filter(
			$data->floorZones('floorZones'),
			static fn (array $zone): bool => isset($materials[$zone['materialId']]),
		)));
	}

	/** @param array<int, Material> $materials */
	public static function ceiling(SnapshotReader $data, array $materials): ?string
	{
		return RoomZones::encode(array_map(
			static fn (array $zone): array => [
				...$zone,
				'materialId' => $zone['materialId'] !== null && isset($materials[$zone['materialId']]) ? $zone['materialId'] : null,
			],
			$data->ceilingZones('ceilingZones'),
		));
	}
}
