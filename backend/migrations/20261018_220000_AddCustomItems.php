<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Item kind `Custom`: a piece of furniture drawn from a furniture definition, `items.definition_id`. Definitions are only
 * soft-deleted, so the key is set to null only when a whole project or user goes (and its items with it).
 */
final class AddCustomItemsMigration extends Migration
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
	];

	public function up(): void
	{
		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: [...self::ItemKinds, 'Custom'])
			->addColumn('definition_id', Type::Int, size: 11, nullable: true)
			->addIndex(['definition_id'], 'items_definition_id_index', false)
			->addForeignKey('definition_id', 'furniture_definitions', 'id', 'items_definition_id_fk', ReferenceOptionEnum::SetNull)
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM items WHERE kind = 'Custom'");
		$pdo->exec(
			'ALTER TABLE items DROP FOREIGN KEY items_definition_id_fk, DROP KEY items_definition_id_index, DROP COLUMN definition_id',
		);
		$this->table('items')->alterColumn('kind', Type::Enum, enum: self::ItemKinds)->alter();
	}
}
