<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * The `params` of a column or chimney (see ItemKindCatalog and the viewer's structure builder): `shape` must be one the
 * viewer draws; a chimney's `aboveRoof` (mm over the highest roof it passes) and `cap` (a metal cap on its top). Other
 * keys are kept as they are.
 */
final readonly class StructureParamsValidator
{
	private const array Shapes = ['rect', 'round'];

	/** mm, the most a chimney rises over the roof. */
	public const int MaxAboveRoof = 5000;

	/** @param array<string, mixed> $params */
	public static function validate(ItemKindEnum $kind, array $params): void
	{
		if ($kind !== ItemKindEnum::Column && $kind !== ItemKindEnum::Chimney) {
			return;
		}

		if (array_key_exists('shape', $params) && !in_array($params['shape'], self::Shapes, true)) {
			throw EditException::invalid(sprintf('%s "shape" must be "rect" or "round".', $kind->value));
		}
		if ($kind !== ItemKindEnum::Chimney) {
			return;
		}
		$above = $params['aboveRoof'] ?? 0;
		if (!is_int($above) || $above < 0 || $above > self::MaxAboveRoof) {
			throw EditException::invalid(sprintf('Chimney "aboveRoof" must be a whole number of mm between 0 and %d.', self::MaxAboveRoof));
		}
		if (array_key_exists('cap', $params) && !is_bool($params['cap'])) {
			throw EditException::invalid('Chimney "cap" must be true or false.');
		}
	}
}
