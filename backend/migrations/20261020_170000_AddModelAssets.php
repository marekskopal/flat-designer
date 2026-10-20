<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * 3D models of bought products (glTF binary, `.glb`), stored in object storage like project files: global (no owner),
 * a user's library or a project's own, with their native size in mm, licence and source. Item kind `Model` draws one,
 * `items.model_id`. Models are soft-deleted, so items keep drawing them.
 */
final class AddModelAssetsMigration extends Migration
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
	];

	public function up(): void
	{
		$this->table('model_assets')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('user_id', Type::Int, size: 11, nullable: true)
			->addColumn('project_id', Type::Int, size: 11, nullable: true)
			->addColumn('name', Type::String)
			->addColumn('description', Type::Text)
			->addColumn('filename', Type::String)
			->addColumn('storage_key', Type::String, size: 512)
			->addColumn('size', Type::Int)
			->addColumn('width', Type::Int)
			->addColumn('depth', Type::Int)
			->addColumn('height', Type::Int)
			->addColumn('license', Type::String)
			->addColumn('author', Type::String)
			->addColumn('source_url', Type::String, size: 512)
			->addColumn('created_by_id', Type::Int, size: 11, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['user_id'], 'model_assets_user_id_index', false)
			->addIndex(['project_id'], 'model_assets_project_id_index', false)
			->addForeignKey('user_id', 'users', 'id', 'model_assets_user_id_fk')
			->addForeignKey('project_id', 'projects', 'id', 'model_assets_project_id_fk')
			->addForeignKey('created_by_id', 'users', 'id', 'model_assets_created_by_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('items')
			->alterColumn('kind', Type::Enum, enum: [...self::ItemKinds, 'Model'])
			->addColumn('model_id', Type::Int, size: 11, nullable: true)
			->addIndex(['model_id'], 'items_model_id_index', false)
			->addForeignKey('model_id', 'model_assets', 'id', 'items_model_id_fk', ReferenceOptionEnum::SetNull)
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM items WHERE kind = 'Model'");
		$pdo->exec('ALTER TABLE items DROP FOREIGN KEY items_model_id_fk, DROP KEY items_model_id_index, DROP COLUMN model_id');
		$this->table('items')->alterColumn('kind', Type::Enum, enum: self::ItemKinds)->alter();
		$this->table('model_assets')->drop();
	}
}
