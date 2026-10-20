<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\DoorLeafStyleEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\ShutterStyleEnum;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * How an opening looks, for `OpeningHandler`: the face of a door's leaves, and a window's solid lower panel and
 * exterior shutters. Fields that do not apply to the type get their defaults.
 */
final readonly class OpeningLook
{
	public const int MaxLeafPanels = 6;

	/** Glass a window keeps above its solid lower panel, mm. */
	private const int MinGlassAbovePanel = 200;

	public function __construct(private MaterialResolver $materialResolver)
	{
	}

	/** @return array{leafStyle: string, leafPanels: int, panelHeight: int|null, shutters: string|null, shutterMaterialId: int|null, shuttersClosed: bool} */
	public function fields(OpeningTypeEnum $type, int $height, FieldReader $fields): array
	{
		return self::leaf($type, $fields) + $this->window($type, $height, $fields);
	}

	/**
	 * `leafStyle` (default Flush) with `leafPanels` panels or panes (1–6, default 2), for doors with a leaf.
	 *
	 * @return array{leafStyle: string, leafPanels: int}
	 */
	private static function leaf(OpeningTypeEnum $type, FieldReader $fields): array
	{
		if (!$type->hasLeaves()) {
			return ['leafStyle' => DoorLeafStyleEnum::Flush->value, 'leafPanels' => 2];
		}

		return [
			'leafStyle' => ($fields->nullableEnum('leafStyle', DoorLeafStyleEnum::class) ?? DoorLeafStyleEnum::Flush)->value,
			'leafPanels' => $fields->raw('leafPanels') === null ? 2 : $fields->int('leafPanels', 1, self::MaxLeafPanels),
		];
	}

	/**
	 * A window's solid lower panel (`panelHeight` mm from the sill, leaving at least 200 mm of glass; none: glass
	 * down to the bottom rail) and exterior `shutters` with their material and `shuttersClosed`.
	 *
	 * @return array{panelHeight: int|null, shutters: string|null, shutterMaterialId: int|null, shuttersClosed: bool}
	 */
	private function window(OpeningTypeEnum $type, int $height, FieldReader $fields): array
	{
		if ($type !== OpeningTypeEnum::Window) {
			return ['panelHeight' => null, 'shutters' => null, 'shutterMaterialId' => null, 'shuttersClosed' => false];
		}
		$panelHeight = $fields->nullableInt('panelHeight', 100, State::MaxSize);
		if ($panelHeight !== null && $panelHeight > $height - self::MinGlassAbovePanel) {
			// Also when the panel was set earlier and the window is being lowered under it.
			throw EditException::invalid(sprintf(
				'A solid lower panel of %d mm leaves less than %d mm of glass in a window %d mm high; lower or clear panelHeight.',
				$panelHeight,
				self::MinGlassAbovePanel,
				$height,
			));
		}
		$shutters = $fields->nullableEnum('shutters', ShutterStyleEnum::class);
		$materialId = $shutters === null ? null : $fields->nullableId('shutterMaterialId');
		$this->materialResolver->resolve($materialId);

		return [
			'panelHeight' => $panelHeight,
			'shutters' => $shutters?->value,
			'shutterMaterialId' => $materialId,
			'shuttersClosed' => $shutters !== null && $fields->bool('shuttersClosed'),
		];
	}
}
