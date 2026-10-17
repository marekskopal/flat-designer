<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Editing: a revision counter per project (bumped by every change, used for optimistic locking and live
 * sync), soft deletes on floor elements (so undo brings back the same row and id), and the change log
 * behind the activity list and undo/redo.
 */
final class AddEditingMigration extends Migration
{
	private const array ElementTables = ['items', 'walls', 'openings', 'rooms'];

	public function up(): void
	{
		$this->table('projects')
			->addColumn('revision', Type::Int, default: 0)
			->alter();

		foreach (self::ElementTables as $table) {
			$this->table($table)
				->addColumn('deleted_at', Type::Timestamp, nullable: true)
				->alter();
		}

		// Removing a room must not take its items along (it used to cascade).
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('ALTER TABLE items DROP FOREIGN KEY items_room_id_fk');
		$pdo->exec(
			'ALTER TABLE items ADD CONSTRAINT items_room_id_fk FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE SET NULL ON UPDATE CASCADE',
		);

		$this->table('project_changes')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('project_id', Type::Int, size: 11)
			->addColumn('floor_id', Type::Int, size: 11, nullable: true)
			->addColumn('user_id', Type::Int, size: 11, nullable: true)
			->addColumn('actor_type', Type::Enum, enum: ['Human', 'Agent'], default: 'Human')
			->addColumn('mcp_client_id', Type::String, size: 128, nullable: true)
			->addColumn('mcp_client_name', Type::String, nullable: true)
			->addColumn('operation', Type::Enum, enum: ['Create', 'Update', 'Delete'])
			->addColumn('element_type', Type::Enum, enum: ['Item', 'Wall', 'Opening', 'Room'])
			->addColumn('element_id', Type::Int)
			->addColumn('summary', Type::String)
			->addColumn('before', Type::LongText, nullable: true)
			->addColumn('after', Type::LongText, nullable: true)
			->addColumn('revision', Type::Int)
			->addColumn('undone_at', Type::Timestamp, nullable: true)
			->addColumn('closed', Type::Boolean, default: false)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['project_id', 'user_id'], 'project_changes_project_id_user_id_index', false)
			->addForeignKey('project_id', 'projects', 'id', 'project_changes_project_id_fk')
			->addForeignKey('floor_id', 'floors', 'id', 'project_changes_floor_id_fk', ReferenceOptionEnum::SetNull)
			->addForeignKey('user_id', 'users', 'id', 'project_changes_user_id_fk', ReferenceOptionEnum::SetNull)
			->create();
	}

	public function down(): void
	{
		$this->table('project_changes')->drop();

		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('ALTER TABLE items DROP FOREIGN KEY items_room_id_fk');
		$pdo->exec(
			'ALTER TABLE items ADD CONSTRAINT items_room_id_fk FOREIGN KEY (room_id) REFERENCES rooms (id) ON DELETE CASCADE ON UPDATE CASCADE',
		);

		foreach (self::ElementTables as $table) {
			$pdo->exec(sprintf('DELETE FROM %s WHERE deleted_at IS NOT NULL', $table));
			$this->table($table)->dropColumn('deleted_at')->alter();
		}

		$this->table('projects')->dropColumn('revision')->alter();
	}
}
