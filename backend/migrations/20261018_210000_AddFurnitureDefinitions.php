<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Furniture definitions: custom furniture as data (parameters and parts, `definition` is JSON format 1). The scope
 * follows from the owners: `user_id` = a user's library, `project_id` = one project, neither = global. Rows are
 * soft-deleted (`deleted_at`) so items using them keep rendering.
 */
final class AddFurnitureDefinitionsMigration extends Migration
{
	public function up(): void
	{
		$this->table('furniture_definitions')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('user_id', Type::Int, size: 11, nullable: true)
			->addColumn('project_id', Type::Int, size: 11, nullable: true)
			->addColumn('name', Type::String)
			->addColumn('description', Type::Text)
			->addColumn('definition', Type::Text)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_by_id', Type::Int, size: 11, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['user_id'], 'furniture_definitions_user_id_index', false)
			->addIndex(['project_id'], 'furniture_definitions_project_id_index', false)
			->addForeignKey('user_id', 'users', 'id', 'furniture_definitions_user_id_fk')
			->addForeignKey('project_id', 'projects', 'id', 'furniture_definitions_project_id_fk')
			->addForeignKey('created_by_id', 'users', 'id', 'furniture_definitions_created_by_id_fk', ReferenceOptionEnum::SetNull)
			->create();
	}

	public function down(): void
	{
		$this->table('furniture_definitions')->drop();
	}
}
