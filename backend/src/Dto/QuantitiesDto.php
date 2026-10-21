<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/**
 * Quantities and cost estimate of a project or one floor (working copy): the surfaces of each room, what to buy per
 * material (`materials`), the boards of the Custom furniture per material (`furniture`, from the cut list) and the
 * `total` of everything priced, in `currency`. `complete` is false when something has no price. Room costs count whole
 * pieces per room, the materials whole pieces for all rooms together, so the total may be a little less than the sum of
 * the rooms.
 */
final readonly class QuantitiesDto
{
	/**
	 * @param list<QuantityRoomDto> $rooms
	 * @param list<QuantityMaterialDto> $materials
	 * @param list<QuantityMaterialDto> $furniture
	 */
	public function __construct(
		public string $currency,
		public int $wastePercent,
		public array $rooms,
		public array $materials,
		public array $furniture,
		public float $total,
		public bool $complete,
	) {
	}
}
