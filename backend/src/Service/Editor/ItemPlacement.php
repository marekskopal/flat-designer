<?php

declare(strict_types=1);

namespace Izbik\Service\Editor;

use Izbik\Model\Entity\Enum\ItemKindEnum;
use Izbik\Service\Editor\Exception\EditException;

/** Where an item stands: on the floor, the ground (`onGround`) or the roof (`onRoof`); a building element never on the roof. */
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
