<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Service\Furniture\DefinitionCategoryEnum;
use stdClass;

/**
 * Points of one room alike: the same definition with the same parameter values (int, bool and select ones, defaults
 * filled in) at the same elevation `z` (mm, the item's bottom edge). `outlets` sums a socket's `gangs` (one without).
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
		public int $outlets,
		public array $itemIds,
	) {
	}
}
