<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Item kind `Wardrobe`: a built-in wardrobe with sliding doors. */
final class AddWardrobeItemKindMigration extends Migration
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
	];

	public function up(): void
	{
		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: [...self::ItemKinds, 'Wardrobe'])
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("DELETE FROM items WHERE kind = 'Wardrobe'");
		$this->table('items')->alterColumn('kind', Type::Enum, enum: self::ItemKinds)->alter();
	}
}
