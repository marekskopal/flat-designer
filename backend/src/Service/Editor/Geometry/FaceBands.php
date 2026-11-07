<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Geometry;

use Izbik\Dto\WallFinishDto;
use const SORT_REGULAR;

/**
 * Which of a wall face's own finish bands line which stretch of it. A band without `from` / `to` lines the whole face,
 * one with them the stretch between (mm along the wall's centre line from its start; a missing end: the face's). Where
 * a face has no band, it is left to the room's bands (a façade: to its default), unless it has a band lining all of it
 * or its list is empty (a bare face). Bands keep their order, where they overlap the first winning as before. Twin of
 * `faceBandStretches()` / `faceBandsAt()` (`viewer/engine/face-bands.ts`), kept identical by
 * `tests/fixtures/face-bands.json`.
 */
final readonly class FaceBands
{
	/**
	 * The bands lining the face at `along`; null where the face has none of its own there.
	 *
	 * @param list<WallFinishDto>|null $bands
	 * @return list<WallFinishDto>|null
	 */
	public static function at(?array $bands, float $along): ?array
	{
		if ($bands === null) {
			return null;
		}
		$lining = array_values(
			array_filter($bands, static fn (WallFinishDto $band): bool => !$band->isRanged() || self::covers($band, $along)),
		);
		$whole = array_filter($bands, static fn (WallFinishDto $band): bool => !$band->isRanged());

		return $lining === [] && $whole === [] && $bands !== [] ? null : $lining;
	}

	/**
	 * The stretches of `from`–`to` along the wall and the bands lining each (null: none of the face's own), in order,
	 * neighbours with the same bands joined.
	 *
	 * @param list<WallFinishDto>|null $bands
	 * @return list<array{from: float, to: float, bands: list<WallFinishDto>|null}>
	 */
	public static function stretches(?array $bands, float $from, float $to): array
	{
		$cuts = [$from, $to];
		foreach ($bands ?? [] as $band) {
			foreach ([$band->from, $band->to] as $end) {
				if ($end !== null && $end > $from && $end < $to) {
					$cuts[] = (float) $end;
				}
			}
		}
		$cuts = array_values(array_unique($cuts, SORT_REGULAR));
		sort($cuts);

		$stretches = [];
		for ($i = 1, $count = count($cuts); $i < $count; $i++) {
			$lining = self::at($bands, ($cuts[$i - 1] + $cuts[$i]) / 2);
			$last = count($stretches) - 1;
			if ($last >= 0 && $stretches[$last]['bands'] === $lining) {
				$stretches[$last]['to'] = $cuts[$i];
				continue;
			}
			$stretches[] = ['from' => $cuts[$i - 1], 'to' => $cuts[$i], 'bands' => $lining];
		}

		return $stretches;
	}

	private static function covers(WallFinishDto $band, float $along): bool
	{
		return ($band->from === null || $band->from <= $along) && ($band->to === null || $along <= $band->to);
	}
}
