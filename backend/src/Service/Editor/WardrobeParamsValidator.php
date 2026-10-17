<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Service\Editor\Exception\EditException;
use const PHP_INT_MAX;

/**
 * The `params` of a sliding-door wardrobe (see ItemKindCatalog and the viewer's furniture builder): the keys
 * the viewer reads must have the right shape; other keys are kept as they are. Whether `profileMaterialId`
 * exists is checked by the item handler.
 */
final readonly class WardrobeParamsValidator
{
	public const int MinDoors = 2;
	public const int MaxDoors = 6;

	/** @param array<string, mixed> $params */
	public static function validate(ItemKindEnum $kind, array $params, int $height): void
	{
		if ($kind !== ItemKindEnum::Wardrobe) {
			return;
		}

		if (array_key_exists('doors', $params) && !self::isIntBetween($params['doors'], self::MinDoors, self::MaxDoors)) {
			throw EditException::invalid(sprintf('Wardrobe "doors" must be %d to %d.', self::MinDoors, self::MaxDoors));
		}
		if (array_key_exists('plinth', $params) && !self::isIntBetween($params['plinth'], 0, $height - 1)) {
			throw EditException::invalid(
				sprintf('Wardrobe "plinth" must be whole mm from 0 to %d (below the wardrobe height).', $height - 1),
			);
		}
		if (array_key_exists('profileMaterialId', $params) && !self::isIntBetween($params['profileMaterialId'], 1, PHP_INT_MAX)) {
			throw EditException::invalid('Wardrobe "profileMaterialId" must be a material id.');
		}
		if (!array_key_exists('mirrorDoors', $params)) {
			return;
		}

		$doors = is_int($params['doors'] ?? null) ? $params['doors'] : self::MaxDoors;
		self::validateMirrorDoors($params['mirrorDoors'], $doors);
	}

	private static function validateMirrorDoors(mixed $mirrorDoors, int $doors): void
	{
		$message = sprintf('Wardrobe "mirrorDoors" must be a list of distinct door numbers from 1 to %d (left to right).', $doors);
		if (!is_array($mirrorDoors) || !array_is_list($mirrorDoors)) {
			throw EditException::invalid($message);
		}
		$seen = [];
		foreach ($mirrorDoors as $door) {
			if (!is_int($door) || !self::isIntBetween($door, 1, $doors) || isset($seen[$door])) {
				throw EditException::invalid($message);
			}
			$seen[$door] = true;
		}
	}

	private static function isIntBetween(mixed $value, int $min, int $max): bool
	{
		return is_int($value) && $value >= $min && $value <= $max;
	}
}
