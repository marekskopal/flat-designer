<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Item groups (assemblies such as a hall stand or a kitchen run) that move, duplicate and delete together,
 * and change batches: one editor operation logging several element changes that undo and redo as one.
 */
final class AddItemGroupsMigration extends Migration
{
	private const array ElementTypes = ['Item', 'Wall', 'Opening', 'Room'];

	public function up(): void
	{
		$this->table('item_groups')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'item_groups_floor_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'item_groups_floor_id_fk')
			->create();

		$this->table('items')
			->addColumn('group_id', Type::Int, size: 11, nullable: true)
			->addIndex(['group_id'], 'items_group_id_index', false)
			->addForeignKey('group_id', 'item_groups', 'id', 'items_group_id_fk', ReferenceOptionEnum::SetNull)
			->alter();

		$this->table('project_changes')
			->addColumn('batch', Type::String, size: 32, nullable: true)
			->alterColumn('element_type', Type::Enum, enum: [...self::ElementTypes, 'Group'])
			->addIndex(['project_id', 'batch'], 'project_changes_project_id_batch_index', false)
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM project_changes WHERE element_type = 'Group'");
		$pdo->exec('ALTER TABLE project_changes DROP KEY project_changes_project_id_batch_index, DROP COLUMN batch');
		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: self::ElementTypes)
			->alter();

		$pdo->exec('ALTER TABLE items DROP FOREIGN KEY items_group_id_fk, DROP KEY items_group_id_index, DROP COLUMN group_id');

		$this->table('item_groups')->drop();
	}
}
