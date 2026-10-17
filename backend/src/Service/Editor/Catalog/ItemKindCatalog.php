<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Catalog;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;

/**
 * What every item kind is, the size and elevation a new one starts with, and the `params` the viewer
 * understands (see the builders in frontend/src/app/viewer/engine). Served to MCP clients and the editor.
 */
final readonly class ItemKindCatalog
{
	private const string CabinetParams = 'plinth: mm of recessed plinth under the fronts (base/tall, e.g. 100); handle: "horizontal" | '
		. '"vertical" | "none"; fronts: list from the bottom up of {type: "door" | "drawer" | "oven" | "microwave", height?: mm, '
		. 'count?: leaves side by side} — fronts without height share the rest; blindRight: mm of the front hidden behind the '
		. 'adjoining run in a corner.';

	private const string WardrobeParams = 'doors: sliding leaves, 2 to 6 (default: one per ~900 mm of width); mirrorDoors: list of door numbers '
		. 'counted from the left as seen from the front, e.g. [3], faced with mirror; profileMaterialId: material of the frames and '
		. 'tracks (default aluminium); plinth: mm under the doors (default 60).';

	private const array Kinds = [
		'BaseCabinet' => ['description' => 'Kitchen base cabinet standing on the floor, front facing the rotation direction.', 'width' => 600, 'depth' => 580, 'height' => 860, 'z' => 0, 'params' => self::CabinetParams],
		'WallCabinet' => ['description' => 'Kitchen wall cabinet hung on the wall; z is its bottom edge.', 'width' => 600, 'depth' => 350, 'height' => 720, 'z' => 1450, 'params' => self::CabinetParams],
		'TallCabinet' => ['description' => 'Tall kitchen cabinet / appliance column (oven, microwave fronts).', 'width' => 600, 'depth' => 600, 'height' => 2200, 'z' => 0, 'params' => self::CabinetParams],
		'Worktop' => ['description' => 'Worktop slab on top of base cabinets (z = cabinet height).', 'width' => 1200, 'depth' => 600, 'height' => 38, 'z' => 860, 'params' => 'none'],
		'Backsplash' => ['description' => 'Wall panel between worktop and wall cabinets.', 'width' => 1200, 'depth' => 16, 'height' => 550, 'z' => 900, 'params' => 'none'],
		'Sink' => ['description' => 'Inset sink with tap; place it on the worktop (z = worktop top).', 'width' => 780, 'depth' => 480, 'height' => 320, 'z' => 898, 'params' => 'bowls: 1 or 2 (1.5-bowl).'],
		'Hob' => ['description' => 'Induction hob set into the worktop.', 'width' => 590, 'depth' => 520, 'height' => 6, 'z' => 898, 'params' => 'none'],
		'LedStrip' => ['description' => 'LED strip, e.g. under wall cabinets.', 'width' => 1000, 'depth' => 20, 'height' => 8, 'z' => 1440, 'params' => 'color: "#rrggbb" light colour; intensity: number (default 5).'],
		'Box' => ['description' => 'Plain block in the item material (pre-wall, chimney, bench, …).', 'width' => 600, 'depth' => 600, 'height' => 600, 'z' => 0, 'params' => 'none'],
		'Bathtub' => ['description' => 'Built-in bathtub with a front panel; drain at the +x end.', 'width' => 1700, 'depth' => 700, 'height' => 560, 'z' => 0, 'params' => 'panelMaterialId: material id of the front panel (e.g. wall tiles).'],
		'Washbasin' => ['description' => 'Wall-hung washbasin with tap; z is its bottom.', 'width' => 600, 'depth' => 450, 'height' => 150, 'z' => 700, 'params' => 'bowl: "small" for a hand basin.'],
		'Toilet' => ['description' => 'Wall-hung toilet bowl.', 'width' => 360, 'depth' => 530, 'height' => 420, 'z' => 0, 'params' => 'none'],
		'WashingMachine' => ['description' => 'Front-loading washing machine.', 'width' => 600, 'depth' => 600, 'height' => 850, 'z' => 0, 'params' => 'none'],
		'Mirror' => ['description' => 'Wall mirror; z is its bottom edge.', 'width' => 600, 'depth' => 8, 'height' => 800, 'z' => 1150, 'params' => 'none'],
		'TowelRadiator' => ['description' => 'Ladder towel radiator on the wall.', 'width' => 500, 'depth' => 30, 'height' => 1600, 'z' => 200, 'params' => 'none'],
		'FlushPlate' => ['description' => 'Toilet flush plate on the pre-wall.', 'width' => 250, 'depth' => 10, 'height' => 165, 'z' => 1000, 'params' => 'none'],
		'Fixture' => ['description' => 'Chrome bathroom fixture on a wall.', 'width' => 60, 'depth' => 70, 'height' => 60, 'z' => 600, 'params' => 'type: "bathMixer" | "showerHead" | "bidetSpray".'],
		'Fence' => ['description' => 'Garden panel fence along x with posts.', 'width' => 2500, 'depth' => 60, 'height' => 1530, 'z' => 0, 'params' => 'panelLength: mm between posts (default 2500); baseBoard: mm of concrete base board.'],
		'Wardrobe' => ['description' => 'Built-in wardrobe with sliding doors on two tracks in aluminium frames; the item material is the carcass and door panels.', 'width' => 2400, 'depth' => 600, 'height' => 2400, 'z' => 0, 'params' => self::WardrobeParams],
	];

	/** @return list<array{kind: string, description: string, width: int, depth: int, height: int, z: int, params: string}> */
	public function all(): array
	{
		$kinds = [];
		foreach (ItemKindEnum::cases() as $case) {
			$kinds[] = ['kind' => $case->value] + $this->get($case);
		}

		return $kinds;
	}

	/** @return array{description: string, width: int, depth: int, height: int, z: int, params: string} */
	public function get(ItemKindEnum $kind): array
	{
		// Every kind has an entry; PHPStan reports a kind added to the enum without one.
		return self::Kinds[$kind->value];
	}
}
