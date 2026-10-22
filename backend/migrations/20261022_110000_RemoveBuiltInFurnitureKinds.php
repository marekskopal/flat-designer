<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Drops the built-in furniture kinds from `items.kind` once `ConvertBuiltInFurnitureMigration` has turned their items
 * into Custom items of the definitions that replaced them. `down()` only gives the column the kinds back.
 */
final class RemoveBuiltInFurnitureKindsMigration extends Migration
{
	private const array ItemKinds = [
		'Sink',
		'Hob',
		'LedStrip',
		'Box',
		'Bathtub',
		'Washbasin',
		'Toilet',
		'WashingMachine',
		'Mirror',
		'TowelRadiator',
		'FlushPlate',
		'Fixture',
		'Fence',
		'Custom',
		'Model',
		'Column',
		'Beam',
	];

	private const array BuiltInKinds = ['BaseCabinet', 'WallCabinet', 'TallCabinet', 'Worktop', 'Backsplash', 'Wardrobe', 'Chair', 'Table'];

	public function up(): void
	{
		$this->table('items')->alterColumn('kind', Type::Enum, enum: self::ItemKinds)->alter();
	}

	public function down(): void
	{
		$this->table('items')->alterColumn('kind', Type::Enum, enum: [...self::BuiltInKinds, ...self::ItemKinds])->alter();
	}
}
