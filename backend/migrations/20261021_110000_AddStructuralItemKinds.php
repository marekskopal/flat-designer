<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Item kinds `Column` and `Beam`: structural elements drawn and cut by the section plane like walls. */
final class AddStructuralItemKindsMigration extends Migration
{
	private const array ItemKinds = [
		'BaseCabinet',
		'WallCabinet',
		'TallCabinet',
		'Worktop',
		'Backsplash',
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
		'Wardrobe',
		'Chair',
		'Table',
		'Custom',
		'Model',
	];

	public function up(): void
	{
		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: [...self::ItemKinds, 'Column', 'Beam'])
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("DELETE FROM items WHERE kind IN ('Column', 'Beam')");
		$this->table('items')->alterColumn('kind', Type::Enum, enum: self::ItemKinds)->alter();
	}
}
