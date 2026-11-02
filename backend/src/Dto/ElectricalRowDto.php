<?php

declare(strict_types=1);

namespace Izbik\Dto;

use Izbik\Service\Furniture\DefinitionCategoryEnum;
use stdClass;

/**
 * Points of one room alike: the same definition with the same parameter values (the int, bool and select ones its
 * `schedule.values` lists, all of them without; defaults filled in) at the same elevation `z` (mm, the item's bottom
 * edge). `units` sums the parameter its `schedule.count` names (a socket's gangs, a data socket's ports), 1 per item
 * without.
 */
final readonly class ElectricalRowDto
{
	/**
	 * @param array<string, int|bool|string>|stdClass $values
	 * @param list<int> $itemIds
	 */
	public function __construct(
		public DefinitionCategoryEnum $category,
		public int $definitionId,
		public string $name,
		public array|stdClass $values,
		public int $z,
		public int $count,
		public int $units,
		public array $itemIds,
	) {
	}
}
