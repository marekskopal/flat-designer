<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * The `params` of a column (see ItemKindCatalog and the viewer's structure builder): `shape` must be one the viewer
 * draws; other keys are kept as they are.
 */
final readonly class ColumnParamsValidator
{
	private const array Shapes = ['rect', 'round'];

	/** @param array<string, mixed> $params */
	public static function validate(ItemKindEnum $kind, array $params): void
	{
		if ($kind !== ItemKindEnum::Column || !array_key_exists('shape', $params)) {
			return;
		}

		if (!in_array($params['shape'], self::Shapes, true)) {
			throw EditException::invalid('Column "shape" must be "rect" or "round".');
		}
	}
}
