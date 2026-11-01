<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Section lines (FD-94): `section_lines`, a cut through the building drawn on a floor's plan, and its element type. */
final class AddSectionLinesMigration extends Migration
{
	private const array ElementTypes = ['Item', 'Wall', 'Opening', 'Room', 'Group', 'VariantSet', 'Plot', 'Stair', 'Roof', 'RoofWindow', 'FloorVoid', 'Railing'];

	public function up(): void
	{
		$this->table('section_lines')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('start_x', Type::Int)
			->addColumn('start_y', Type::Int)
			->addColumn('end_x', Type::Int)
			->addColumn('end_y', Type::Int)
			->addColumn('depth', Type::Int, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'section_lines_floor_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'section_lines_floor_id_fk')
			->create();

		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: [...self::ElementTypes, 'SectionLine'])
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM project_changes WHERE element_type = 'SectionLine'");
		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: self::ElementTypes)
			->alter();
		$this->table('section_lines')->drop();
	}
}
