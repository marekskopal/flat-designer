<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/** Plots (FD-39): the land a floor's building stands on, outlined in plan mm, with its ground's material. */
final class AddPlotsMigration extends Migration
{
	private const array ElementTypes = ['Item', 'Wall', 'Opening', 'Room', 'Group', 'VariantSet'];

	public function up(): void
	{
		$this->table('plots')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('polygon', Type::Text)
			->addColumn('ground_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'plots_floor_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'plots_floor_id_fk')
			->addForeignKey('ground_material_id', 'materials', 'id', 'plots_ground_material_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: [...self::ElementTypes, 'Plot'])
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM project_changes WHERE element_type = 'Plot'");
		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: self::ElementTypes)
			->alter();
		$this->table('plots')->drop();
	}
}
