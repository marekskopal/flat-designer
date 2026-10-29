<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\DoorFrameStyleEnum;
use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;

/** The fields a new opening starts with, per type (none: a door), for `OpeningHandler`. */
final class OpeningDefaults
{
	/** A plain opening: no leaf, no glazing bars or sill boards, flush leaves, glass down to the bottom rail, no shutters. */
	private const array Plain = [
		'wallId' => null,
		'offset' => 100,
		'leafWidth' => null,
		'hinge' => null,
		'side' => null,
		'entrance' => false,
		'walkable' => false,
		'frameStyle' => 'Lining',
		'handle' => null,
		'mullions' => 1,
		'transoms' => 0,
		'sillBoard' => null,
		'leafStyle' => 'Flush',
		'leafPanels' => 2,
		'panelHeight' => null,
		'shutters' => null,
		'shutterMaterialId' => null,
		'shuttersClosed' => false,
		'depth' => null,
		'revealLining' => false,
	];

	/** @return array<string, mixed> */
	public static function of(?OpeningTypeEnum $type): array
	{
		$type ??= OpeningTypeEnum::Door;
		if ($type === OpeningTypeEnum::Window) {
			return ['name' => 'Window', 'type' => $type->value, 'width' => 1200, 'height' => 1200, 'sillHeight' => 900] + self::Plain;
		}
		if ($type === OpeningTypeEnum::Niche) {
			// Half the wall deep, set by the handler.
			return [
				'name' => 'Niche',
				'type' => $type->value,
				'width' => 600,
				'height' => 600,
				'sillHeight' => 900,
				'side' => OpeningSideEnum::Left->value,
				'frameStyle' => DoorFrameStyleEnum::None->value,
			] + self::Plain;
		}

		return [
			'name' => ucfirst($type->label()),
			'type' => $type->value,
			'width' => $type === OpeningTypeEnum::DoubleDoor ? 1400 : 900,
			'height' => $type === OpeningTypeEnum::Passage ? 2100 : 1970,
			'sillHeight' => 0,
			'leafWidth' => $type === OpeningTypeEnum::Door ? 800 : null,
			'hinge' => $type->hasLeaves() ? OpeningHingeEnum::Start->value : null,
			'side' => $type->hasLeaves() ? OpeningSideEnum::Left->value : null,
			'frameStyle' => $type === OpeningTypeEnum::Passage ? DoorFrameStyleEnum::None->value : DoorFrameStyleEnum::Lining->value,
		] + self::Plain;
	}
}
