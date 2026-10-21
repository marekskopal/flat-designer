<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Prices for the quantities and cost estimate: a project's price per material (or per surface's default look, when
 * `material_id` is null) and the project's currency and waste allowance for tiles and planks.
 */
final class AddMaterialPricesMigration extends Migration
{
	private const array Surfaces = ['Floor', 'WallFinish', 'Wall', 'Ceiling', 'Beams', 'Skirting', 'Furniture'];

	public function up(): void
	{
		$this->table('projects')
			->addColumn('currency', Type::String, size: 3, default: 'EUR')
			->addColumn('waste_percent', Type::Int, default: 10)
			->alter();

		$this->table('project_material_prices')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('project_id', Type::Int, size: 11)
			->addColumn('material_id', Type::Int, size: 11, nullable: true)
			->addColumn('surface', Type::Enum, enum: self::Surfaces, nullable: true)
			->addColumn('price_per_area', Type::Double, nullable: true)
			->addColumn('price_per_length', Type::Double, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['project_id'], 'project_material_prices_project_id_index', false)
			->addIndex(['material_id'], 'project_material_prices_material_id_index', false)
			->addForeignKey('project_id', 'projects', 'id', 'project_material_prices_project_id_fk')
			->addForeignKey('material_id', 'materials', 'id', 'project_material_prices_material_id_fk')
			->create();
	}

	public function down(): void
	{
		$this->table('project_material_prices')->drop();
		$this->table('projects')->dropColumn('currency')->dropColumn('waste_percent')->alter();
	}
}
