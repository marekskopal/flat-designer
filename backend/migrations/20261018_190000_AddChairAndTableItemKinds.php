<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Item kinds `Chair` and `Table`: solid wood dining furniture. */
final class AddChairAndTableItemKindsMigration extends Migration
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
	];

	public function up(): void
	{
		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: [...self::ItemKinds, 'Chair', 'Table'])
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("DELETE FROM items WHERE kind IN ('Chair', 'Table')");
		$this->table('items')->alterColumn('kind', Type::Enum, enum: self::ItemKinds)->alter();
	}
}
