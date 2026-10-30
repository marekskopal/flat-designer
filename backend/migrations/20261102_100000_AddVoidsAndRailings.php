<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Floor voids and railings (FD-90): `floor_voids` (a hole through a floor's slab, its edges lined), `railings` (a
 * polyline balustrade) and the stairs' railing look (`railing_style`, `railing_material_id`), wall handrails and the
 * lining of their voids.
 */
final class AddVoidsAndRailingsMigration extends Migration
{
	private const array ElementTypes = ['Item', 'Wall', 'Opening', 'Room', 'Group', 'VariantSet', 'Plot', 'Stair', 'Roof', 'RoofWindow'];

	private const array Styles = ['Balusters', 'Rods', 'Glass', 'Panel', 'Cable'];

	public function up(): void
	{
		$this->table('floor_voids')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('polygon', Type::Text)
			->addColumn('lining_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('lining_depth', Type::Int, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'floor_voids_floor_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'floor_voids_floor_id_fk')
			->addForeignKey('lining_material_id', 'materials', 'id', 'floor_voids_lining_material_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('railings')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('points', Type::Text)
			->addColumn('height', Type::Int)
			->addColumn('base_z', Type::Int, default: 0)
			->addColumn('style', Type::Enum, enum: self::Styles)
			->addColumn('spacing', Type::Int, nullable: true)
			->addColumn('post_spacing', Type::Int)
			->addColumn('handrail', Type::Enum, enum: ['Round', 'Square', 'Flat', 'None'])
			->addColumn('material_id', Type::Int, size: 11, nullable: true)
			->addColumn('infill_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'railings_floor_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'railings_floor_id_fk')
			->addForeignKey('material_id', 'materials', 'id', 'railings_material_id_fk', ReferenceOptionEnum::SetNull)
			->addForeignKey('infill_material_id', 'materials', 'id', 'railings_infill_material_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('stairs')
			->addColumn('railing_style', Type::Enum, enum: self::Styles, nullable: true)
			->addColumn('railing_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('wall_handrail', Type::Enum, enum: ['None', 'Left', 'Right', 'Both'], default: 'None')
			->addColumn('void_lining_material_id', Type::Int, size: 11, nullable: true)
			->addForeignKey('railing_material_id', 'materials', 'id', 'stairs_railing_material_id_fk', ReferenceOptionEnum::SetNull)
			->addForeignKey('void_lining_material_id', 'materials', 'id', 'stairs_void_lining_material_id_fk', ReferenceOptionEnum::SetNull)
			->alter();

		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: [...self::ElementTypes, 'FloorVoid', 'Railing'])
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec("DELETE FROM project_changes WHERE element_type IN ('FloorVoid', 'Railing')");
		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: self::ElementTypes)
			->alter();
		$this->table('stairs')
			->dropForeignKey('stairs_railing_material_id_fk')
			->dropForeignKey('stairs_void_lining_material_id_fk')
			->dropColumn('railing_style')
			->dropColumn('railing_material_id')
			->dropColumn('wall_handrail')
			->dropColumn('void_lining_material_id')
			->alter();
		$this->table('railings')->drop();
		$this->table('floor_voids')->drop();
	}
}
