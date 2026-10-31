<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Service\Editor\Exception\EditException;

/** Where an item stands: on the floor, the ground (`onGround`, FD-86) or the roof (`onRoof`, FD-91); a building element never on the roof. */
final readonly class ItemPlacement
{
	public static function check(ItemKindEnum $kind, bool $onGround, bool $onRoof): void
	{
		if ($onGround && $onRoof) {
			throw EditException::invalid('An item stands on the ground or lies on the roof, not both.');
		}
		if ($onRoof && $kind->isStructural()) {
			throw EditException::invalid(sprintf('A %s is part of the building and cannot lie on the roof.', strtolower($kind->value)));
		}
	}
}
