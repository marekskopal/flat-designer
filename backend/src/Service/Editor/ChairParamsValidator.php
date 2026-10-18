<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * The `params` of a chair (see ItemKindCatalog and the viewer's furniture builder): the keys the viewer reads
 * must have the right shape; other keys are kept as they are.
 */
final readonly class ChairParamsValidator
{
	/** @param array<string, mixed> $params */
	public static function validate(ItemKindEnum $kind, array $params, int $width, int $height): void
	{
		if ($kind !== ItemKindEnum::Chair) {
			return;
		}

		if (array_key_exists('seatHeight', $params) && !self::isIntBetween($params['seatHeight'], 1, $height - 1)) {
			throw EditException::invalid(
				sprintf('Chair "seatHeight" must be whole mm from 1 to %d (below the top of the back).', $height - 1),
			);
		}
		if (array_key_exists('backWidth', $params) && !self::isIntBetween($params['backWidth'], 1, $width)) {
			throw EditException::invalid(sprintf('Chair "backWidth" must be whole mm from 1 to %d (the front width).', $width));
		}
	}

	private static function isIntBetween(mixed $value, int $min, int $max): bool
	{
		return is_int($value) && $value >= $min && $value <= $max;
	}
}
