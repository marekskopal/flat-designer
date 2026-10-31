<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Chimneys and roof details (FD-91): the item kind `Chimney`, items lying on the roof (`items.on_roof`), a roof's
 * rainwater goods (`gutters`, `gutter_material_id`, `downpipes`), snow guard rows and ridge style, the material pattern
 * `BeaverTail` with beaver-tail clay tiles and titanium zinc, and prices of the surfaces `Gutter` and `Downpipe`.
 */
final class AddRoofDetailsMigration extends Migration
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

	private const array Patterns = ['Plain', 'Wood', 'Planks', 'Tiles', 'Glass', 'Grass', 'Gravel', 'Terrazzo', 'Brick', 'Herringbone'];

	private const array Surfaces = ['Floor', 'WallFinish', 'Wall', 'Ceiling', 'Beams', 'Skirting', 'Furniture', 'RoofCovering', 'Soffit', 'Facade'];

	private const array Codes = ['roof-tiles-beaver-clay', 'zinc-titanium'];

	public function up(): void
	{
		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: [...self::ItemKinds, 'Chimney'])
			->addColumn('on_roof', Type::Boolean, default: false)
			->alter();
		$this->table('roofs')
			->addColumn('gutters', Type::Enum, enum: ['None', 'HalfRound', 'Box'], default: 'None')
			->addColumn('gutter_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('downpipes', Type::Text, nullable: true)
			->addColumn('snow_guards', Type::Int, default: 0)
			->addColumn('ridge_style', Type::Enum, enum: ['Box', 'Round', 'Angled'], default: 'Box')
			->addForeignKey('gutter_material_id', 'materials', 'id', 'roofs_gutter_material_id_fk', ReferenceOptionEnum::SetNull)
			->alter();
		$this->table('materials')
			->alterColumn('pattern', Type::Enum, enum: [...self::Patterns, 'BeaverTail'], default: 'Plain')
			->alter();
		$this->table('project_material_prices')
			->alterColumn('surface', Type::Enum, enum: [...self::Surfaces, 'Gutter', 'Downpipe'], nullable: true)
			->alter();

		$now = date('Y-m-d H:i:s');
		$this->table('materials')->insert([
			[
				'code' => 'roof-tiles-beaver-clay',
				'name' => 'Beaver-tail roof tiles, red clay (180 × 380)',
				'pattern' => 'BeaverTail',
				'color' => '#a2502f',
				'roughness' => 0.85,
				'metalness' => 0.0,
				'tile_width' => 180,
				'tile_height' => 380,
				'created_at' => $now,
				'updated_at' => $now,
			],
			[
				'code' => 'zinc-titanium',
				'name' => 'Titanium zinc, pre-weathered',
				'pattern' => 'Plain',
				'color' => '#8c9194',
				'roughness' => 0.45,
				'metalness' => 0.5,
				'tile_width' => null,
				'tile_height' => null,
				'created_at' => $now,
				'updated_at' => $now,
			],
		]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
		$pdo->exec("UPDATE materials SET pattern = 'Tiles' WHERE pattern = 'BeaverTail'");
		$this->table('materials')->alterColumn('pattern', Type::Enum, enum: self::Patterns, default: 'Plain')->alter();
		$pdo->exec("DELETE FROM project_material_prices WHERE surface IN ('Gutter', 'Downpipe')");
		$this->table('project_material_prices')->alterColumn('surface', Type::Enum, enum: self::Surfaces, nullable: true)->alter();
		$this->table('roofs')
			->dropForeignKey('roofs_gutter_material_id_fk')
			->dropColumn('gutter_material_id')
			->dropColumn('gutters')
			->dropColumn('downpipes')
			->dropColumn('snow_guards')
			->dropColumn('ridge_style')
			->alter();
		$pdo->exec("DELETE FROM items WHERE kind = 'Chimney'");
		$this->table('items')
			->dropColumn('on_roof')
			->alterColumn('kind', Type::Enum, enum: self::ItemKinds)
			->alter();
	}
}
