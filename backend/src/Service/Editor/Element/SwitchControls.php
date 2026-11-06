<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Element;

use Izbik\Model\Entity\Enum\ItemKindEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\FurnitureDefinition;
use Izbik\Model\Entity\Item;
use Izbik\Model\Repository\ItemRepository;
use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Furniture\DefinitionCategoryEnum;
use Izbik\Service\Json\JsonDecoder;

/**
 * A switch item's `controls`: the light items (lights by their definition's category, and LED strips) of the same
 * floor it controls (a light listed by two switches is two-way
 * switching, by more intermediate). Lights on other floors cannot be linked. Only the ids added against the current
 * state are checked, so a light deleted since (still listed, skipped where the links are read) does not block moving,
 * renaming or undoing the switch. An item that stops being a switch drops the list (undoing that brings it back).
 */
final readonly class SwitchControls
{
	public const int MaxControls = 50;

	public function __construct(private ItemRepository $itemRepository)
	{
	}

	/**
	 * @param list<int> $currentIds the switch's list before the change
	 * @return list<int> the ids, distinct and sorted
	 */
	public function normalize(
		Floor $floor,
		mixed $controls,
		array $currentIds,
		?int $itemId,
		ItemKindEnum $kind,
		?FurnitureDefinition $definition,
	): array
	{
		$ids = self::ids($controls);
		if ($ids === []) {
			return [];
		}
		$added = array_values(array_diff($ids, $currentIds));
		$isSwitch = $kind === ItemKindEnum::Custom && self::category($definition) === DefinitionCategoryEnum::Switch;
		if (!$isSwitch && $added !== []) {
			throw EditException::invalid('Only a switch (a Custom item whose definition has the category "switch") controls lights.');
		}
		if (!$isSwitch || $added === []) {
			// An item that is no longer a switch (another definition or kind) drops its lights.
			return $isSwitch ? $ids : [];
		}
		foreach ($added as $id) {
			if ($id === $itemId) {
				throw EditException::invalid('A switch cannot control itself.');
			}
			$light = $this->itemRepository->findOnFloor($floor->id, $id);
			// A deleted light of the floor is let through (skipped where the links are read), so undoing an unlink
			// after the light was deleted does not conflict.
			if ($light === null) {
				throw EditException::invalid(sprintf('Item %d is not on this floor; a switch controls lights on its own floor only.', $id));
			}
			if (!self::isLight($light)) {
				throw EditException::invalid(
					sprintf('Item %d is not a light (a Custom item whose definition has the category "light", or an LED strip).', $id),
				);
			}
		}

		return $ids;
	}

	/** @return list<int> distinct positive ids, sorted */
	public static function ids(mixed $controls): array
	{
		if ($controls === null) {
			return [];
		}
		if (!is_array($controls) || !array_is_list($controls)) {
			throw EditException::invalid('Field "controls" must be a list of item ids.');
		}
		$ids = [];
		foreach ($controls as $id) {
			if (!is_int($id) || $id < 1) {
				throw EditException::invalid('Field "controls" must be a list of item ids.');
			}
			$ids[$id] = $id;
		}
		if (count($ids) > self::MaxControls) {
			throw EditException::invalid(sprintf('A switch controls at most %d lights.', self::MaxControls));
		}
		sort($ids);

		return $ids;
	}

	/** A light a switch may control: a Custom item whose definition has the category "light", or an LED strip. */
	public static function isLight(Item $item): bool
	{
		return $item->kind === ItemKindEnum::LedStrip
			|| $item->kind === ItemKindEnum::Custom && self::category($item->definition) === DefinitionCategoryEnum::Light;
	}

	private static function category(?FurnitureDefinition $definition): ?DefinitionCategoryEnum
	{
		return $definition === null ? null : DefinitionCategoryEnum::of(JsonDecoder::decodeObject($definition->definition));
	}
}
