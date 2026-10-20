<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * A plan image (a project file) laid under the top view of a floor for tracing. One per floor; it goes with the floor
 * and with the file. The placement maps image pixels to plan mm: the top-left pixel at (`x`, `y`), `scale` mm per
 * pixel, turned by `rotation` degrees counter-clockwise.
 */
final class AddFloorUnderlaysMigration extends Migration
{
	public function up(): void
	{
		$this->table('floor_underlays')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('file_id', Type::Int, size: 11)
			->addColumn('width_px', Type::Int)
			->addColumn('height_px', Type::Int)
			->addColumn('x', Type::Int)
			->addColumn('y', Type::Int)
			->addColumn('scale', Type::Double)
			->addColumn('rotation', Type::Double, default: 0)
			->addColumn('opacity', Type::Double, default: 0.5)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'floor_underlays_floor_id_unique', true)
			->addIndex(['file_id'], 'floor_underlays_file_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'floor_underlays_floor_id_fk')
			->addForeignKey('file_id', 'project_files', 'id', 'floor_underlays_file_id_fk')
			->create();
	}

	public function down(): void
	{
		$this->table('floor_underlays')->drop();
	}
}
