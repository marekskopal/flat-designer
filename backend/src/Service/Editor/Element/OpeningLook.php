<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\BoardDirectionEnum;
use FlatDesigner\Model\Entity\Enum\DoorLeafStyleEnum;
use FlatDesigner\Model\Entity\Enum\GarageDoorStyleEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Enum\ShutterStyleEnum;
use FlatDesigner\Model\Entity\Opening;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * How an opening looks, for `OpeningHandler`: the face of a door's leaves, a window's solid lower panel and exterior
 * shutters, the materials of the frame and leaves, a garage door's style and a lift-and-slide door's fixed panes
 * (FD-89). Fields that do not apply to the type get their defaults.
 */
final readonly class OpeningLook
{
	public const int MaxLeafPanels = 6;

	/** Boards of a `Boarded` leaf. */
	public const int MaxBoards = 24;

	/** Fixed panes beside a lift-and-slide door's sliding one. */
	public const int MaxFixedPanes = 3;

	/** Glass a window keeps above its solid lower panel, mm. */
	private const int MinGlassAbovePanel = 200;

	public function __construct(private MaterialResolver $materialResolver)
	{
	}

	/**
	 * @return array{
	 *     leafStyle: string, leafPanels: int, boardDirection: string, panelHeight: int|null, shutters: string|null,
	 *     shutterMaterialId: int|null, shuttersClosed: bool, frameMaterialId: int|null, outsideFrameMaterialId: int|null,
	 *     leafMaterialId: int|null, garageDoorStyle: string|null, fixedPanes: int
	 * }
	 */
	public function fields(OpeningTypeEnum $type, int $height, FieldReader $fields, ?Opening $current = null): array
	{
		return self::leaf($type, $fields, $current) + $this->window($type, $height, $fields) + $this->materials($type, $fields) + [
			// Missing in older change states: a sectional door.
			'garageDoorStyle' => $type === OpeningTypeEnum::GarageDoor
				? ($fields->nullableEnum('garageDoorStyle', GarageDoorStyleEnum::class) ?? GarageDoorStyleEnum::Sectional)->value
				: null,
			'fixedPanes' => $type === OpeningTypeEnum::SlidingDoor && $fields->raw('fixedPanes') !== null
				? $fields->int('fixedPanes', 0, self::MaxFixedPanes, 'panes')
				: 0,
		];
	}

	/**
	 * `leafStyle` (default Flush) with `leafPanels` panels or panes (1–6, default 2) or boards (1–24, default 8) running
	 * `boardDirection` (default Vertical), for doors with a leaf. A leaf restyled without a new count takes the new
	 * style's default (a boarded leaf's 12 boards are no panel count).
	 *
	 * @return array{leafStyle: string, leafPanels: int, boardDirection: string}
	 */
	private static function leaf(OpeningTypeEnum $type, FieldReader $fields, ?Opening $current): array
	{
		if (!$type->hasLeaves()) {
			return ['leafStyle' => DoorLeafStyleEnum::Flush->value, 'leafPanels' => 2, 'boardDirection' => BoardDirectionEnum::Vertical->value];
		}
		$style = $fields->nullableEnum('leafStyle', DoorLeafStyleEnum::class) ?? DoorLeafStyleEnum::Flush;
		$boarded = $style === DoorLeafStyleEnum::Boarded;
		$panels = $fields->raw('leafPanels');
		$restyled = $current !== null && $current->leafStyle !== $style && $panels === $current->leafPanels;

		return [
			'leafStyle' => $style->value,
			'leafPanels' => $panels === null || $restyled
				? ($boarded ? 8 : 2)
				: $fields->int('leafPanels', 1, $boarded ? self::MaxBoards : self::MaxLeafPanels, $boarded ? 'boards' : 'panels'),
			'boardDirection' => $boarded
				? ($fields->nullableEnum('boardDirection', BoardDirectionEnum::class) ?? BoardDirectionEnum::Vertical)->value
				: BoardDirectionEnum::Vertical->value,
		];
	}

	/**
	 * The frame's material (frame and sashes; none for a niche), a window's or glazed sliding door's outside frame
	 * (timber-aluminium) and the leaves' (doors with a leaf); none: the defaults.
	 *
	 * @return array{frameMaterialId: int|null, outsideFrameMaterialId: int|null, leafMaterialId: int|null}
	 */
	private function materials(OpeningTypeEnum $type, FieldReader $fields): array
	{
		$frame = $type === OpeningTypeEnum::Niche ? null : $fields->nullableId('frameMaterialId');
		$outside = $type === OpeningTypeEnum::Window || $type === OpeningTypeEnum::SlidingDoor
			? $fields->nullableId('outsideFrameMaterialId')
			: null;
		$leaf = $type->hasLeaves() ? $fields->nullableId('leafMaterialId') : null;
		foreach ([$frame, $outside, $leaf] as $id) {
			$this->materialResolver->resolve($id);
		}

		return ['frameMaterialId' => $frame, 'outsideFrameMaterialId' => $outside, 'leafMaterialId' => $leaf];
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
