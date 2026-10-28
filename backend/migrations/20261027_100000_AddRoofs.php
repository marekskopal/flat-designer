<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Roofs (FD-38): gable, hip, shed and flat roofs over a floor, the windows in them, walls rising to them
 * (`walls.up_to_roof`), and coverings for them. Material ids are left to auto-increment; they are picked up by code.
 */
final class AddRoofsMigration extends Migration
{
	private const array ElementTypes = ['Item', 'Wall', 'Opening', 'Room', 'Group', 'VariantSet', 'Plot', 'Stair'];

	private const array Codes = ['roof-tiles-clay', 'roof-slate', 'roof-metal-seam', 'roof-membrane'];

	public function up(): void
	{
		$this->table('roofs')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('shape', Type::Enum, enum: ['Gable', 'Hip', 'Shed', 'Flat'])
			->addColumn('x', Type::Int)
			->addColumn('y', Type::Int)
			->addColumn('rotation', Type::Int)
			->addColumn('width', Type::Int)
			->addColumn('depth', Type::Int)
			->addColumn('eave_height', Type::Int)
			->addColumn('pitch', Type::Int)
			->addColumn('overhang', Type::Int)
			->addColumn('verge_overhang', Type::Int)
			->addColumn('thickness', Type::Int)
			->addColumn('parapet', Type::Int, nullable: true)
			->addColumn('covering_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('soffit_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['floor_id'], 'roofs_floor_id_index', false)
			->addForeignKey('floor_id', 'floors', 'id', 'roofs_floor_id_fk')
			->addForeignKey('covering_material_id', 'materials', 'id', 'roofs_covering_material_id_fk', ReferenceOptionEnum::SetNull)
			->addForeignKey('soffit_material_id', 'materials', 'id', 'roofs_soffit_material_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('roof_windows')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('roof_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('type', Type::Enum, enum: ['CentrePivot', 'TopHung'])
			->addColumn('along', Type::Int)
			->addColumn('across', Type::Int)
			->addColumn('width', Type::Int)
			->addColumn('length', Type::Int)
			->addColumn('frame_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('deleted_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['roof_id'], 'roof_windows_roof_id_index', false)
			->addForeignKey('roof_id', 'roofs', 'id', 'roof_windows_roof_id_fk')
			->addForeignKey('frame_material_id', 'materials', 'id', 'roof_windows_frame_material_id_fk', ReferenceOptionEnum::SetNull)
			->create();

		$this->table('walls')
			->addColumn('up_to_roof', Type::Boolean, default: false)
			->alter();

		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: [...self::ElementTypes, 'Roof', 'RoofWindow'])
			->alter();

		$now = date('Y-m-d H:i:s');
		$material = static fn (
			string $code,
			string $name,
			string $pattern,
			string $color,
			float $roughness,
			float $metalness,
			?int $width,
			?int $height,
		): array => [
			'code' => $code,
			'name' => $name,
			'pattern' => $pattern,
			'color' => $color,
			'roughness' => $roughness,
			'metalness' => $metalness,
			'tile_width' => $width,
			'tile_height' => $height,
			'created_at' => $now,
			'updated_at' => $now,
		];
		$this->table('materials')->insert([
			$material('roof-tiles-clay', 'Roof tiles, red clay (300 × 420)', 'Tiles', '#9c4a2e', 0.85, 0.0, 300, 420),
			$material('roof-slate', 'Roof slate, dark grey (300 × 500)', 'Tiles', '#3d4146', 0.8, 0.0, 300, 500),
			$material('roof-metal-seam', 'Standing seam metal roofing, anthracite', 'Plain', '#3f4348', 0.45, 0.6, null, null),
			$material('roof-membrane', 'Flat roof membrane, grey', 'Plain', '#5d6064', 0.9, 0.0, null, null),
		]);
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$codes = implode(', ', array_map(static fn (string $code): string => "'" . $code . "'", self::Codes));
		$pdo->exec('DELETE FROM materials WHERE code IN (' . $codes . ')');
		$pdo->exec("DELETE FROM project_changes WHERE element_type IN ('Roof', 'RoofWindow')");
		$this->table('project_changes')
			->alterColumn('element_type', Type::Enum, enum: self::ElementTypes)
			->alter();
		$this->table('walls')
			->dropColumn('up_to_roof')
			->alter();
		$this->table('roof_windows')->drop();
		$this->table('roofs')->drop();
	}
}
