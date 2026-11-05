<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

use Izbik\Model\Entity\Item;
use Izbik\Model\Repository\ItemRepository;
use Izbik\Service\Editor\Element\SwitchControls;
use const JSON_THROW_ON_ERROR;

/**
 * A restored switch's `controls` (missing in snapshots taken before switch links): its lights by their new ids. Lights
 * the snapshot does not hold (deleted when it was taken) drop out, and the list keeps at most `SwitchControls::MaxControls`.
 */
final class SnapshotSwitchControls
{
	/** @param array<int, array{Item, mixed}> $restored the floor's new items with their stored `controls` by their id in the snapshot */
	public static function restore(array $restored, ItemRepository $itemRepository): void
	{
		foreach ($restored as [$item, $controls]) {
			$ids = self::remap($controls, $restored);
			if ($ids === []) {
				continue;
			}

			$item->controls = json_encode($ids, JSON_THROW_ON_ERROR);
			$itemRepository->persist($item);
		}
	}

	/**
	 * @param array<int, array{Item, mixed}> $restored
	 * @return list<int>
	 */
	private static function remap(mixed $controls, array $restored): array
	{
		$ids = [];
		foreach (is_array($controls) ? $controls : [] as $id) {
			$light = is_int($id) ? ($restored[$id][0] ?? null) : null;
			if ($light !== null) {
				$ids[$light->id] = $light->id;
			}
		}
		sort($ids);

		return array_slice($ids, 0, SwitchControls::MaxControls);
	}
}
