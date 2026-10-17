<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * The `params` of kitchen cabinets (see ItemKindCatalog::CabinetParams and the viewer's kitchen builder):
 * the keys the viewer reads must have the right shape; other keys are kept as they are.
 */
final readonly class CabinetParamsValidator
{
	public const array Kinds = [ItemKindEnum::BaseCabinet, ItemKindEnum::WallCabinet, ItemKindEnum::TallCabinet];
	public const array Handles = ['horizontal', 'vertical', 'none'];
	public const array FrontTypes = ['door', 'drawer', 'oven', 'microwave'];

	private const int MaxFronts = 12;
	private const int MaxLeaves = 4;

	/** @param array<string, mixed> $params */
	public static function validate(ItemKindEnum $kind, array $params, int $width, int $height): void
	{
		if (!in_array($kind, self::Kinds, true)) {
			return;
		}

		if (array_key_exists('plinth', $params) && !self::isIntBetween($params['plinth'], 0, $height - 1)) {
			throw EditException::invalid(
				sprintf('Cabinet "plinth" must be whole mm from 0 to %d (below the cabinet height).', $height - 1),
			);
		}
		if (array_key_exists('handle', $params) && !in_array($params['handle'], self::Handles, true)) {
			throw EditException::invalid(sprintf('Cabinet "handle" must be one of: %s.', implode(', ', self::Handles)));
		}
		if (array_key_exists('blindRight', $params) && !self::isIntBetween($params['blindRight'], 0, $width - 1)) {
			throw EditException::invalid(sprintf('Cabinet "blindRight" must be whole mm from 0 to %d (below the width).', $width - 1));
		}
		if (array_key_exists('fronts', $params)) {
			self::validateFronts($params['fronts'], $height);
		}
	}

	private static function validateFronts(mixed $fronts, int $height): void
	{
		if (!is_array($fronts) || !array_is_list($fronts) || count($fronts) > self::MaxFronts) {
			throw EditException::invalid(sprintf('Cabinet "fronts" must be a list of up to %d fronts.', self::MaxFronts));
		}
		foreach ($fronts as $index => $front) {
			$position = $index + 1;
			if (!is_array($front) || !in_array($front['type'] ?? null, self::FrontTypes, true)) {
				throw EditException::invalid(
					sprintf('Front %d needs a "type": one of %s.', $position, implode(', ', self::FrontTypes)),
				);
			}
			if (array_key_exists('height', $front) && !self::isIntBetween($front['height'], 1, $height)) {
				throw EditException::invalid(sprintf('Front %d: "height" must be whole mm from 1 to %d.', $position, $height));
			}
			if (array_key_exists('count', $front) && !self::isIntBetween($front['count'], 1, self::MaxLeaves)) {
				throw EditException::invalid(sprintf('Front %d: "count" must be 1 to %d leaves.', $position, self::MaxLeaves));
			}
		}
	}

	private static function isIntBetween(mixed $value, int $min, int $max): bool
	{
		return is_int($value) && $value >= $min && $value <= $max;
	}
}
