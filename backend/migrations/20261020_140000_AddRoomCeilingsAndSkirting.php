<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Room ceilings and skirting: the ceiling's material (null: white plaster), a vaulted ceiling between beams
 * (`vault_spacing` null: flat) with the beams' direction, the vaults' rise, the beams' width and drop below the
 * vault springing and their material (null: white paint), and skirting boards (`skirting_height` null: none).
 */
final class AddRoomCeilingsAndSkirtingMigration extends Migration
{
	public function up(): void
	{
		$this->table('rooms')
			->addColumn('ceiling_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('vault_spacing', Type::Int, nullable: true)
			->addColumn('vault_angle', Type::Int, default: 0)
			->addColumn('vault_rise', Type::Int, default: 130)
			->addColumn('beam_width', Type::Int, default: 80)
			->addColumn('beam_drop', Type::Int, default: 30)
			->addColumn('beam_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('skirting_height', Type::Int, nullable: true)
			->addColumn('skirting_material_id', Type::Int, size: 11, nullable: true)
			->addForeignKey('ceiling_material_id', 'materials', 'id', 'rooms_ceiling_material_id_fk', ReferenceOptionEnum::SetNull)
			->addForeignKey('beam_material_id', 'materials', 'id', 'rooms_beam_material_id_fk', ReferenceOptionEnum::SetNull)
			->addForeignKey('skirting_material_id', 'materials', 'id', 'rooms_skirting_material_id_fk', ReferenceOptionEnum::SetNull)
			->alter();
	}

	public function down(): void
	{
		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		foreach (['ceiling_material_id', 'beam_material_id', 'skirting_material_id'] as $column) {
			$pdo->exec(sprintf('ALTER TABLE rooms DROP FOREIGN KEY rooms_%1$s_fk, DROP KEY rooms_%1$s_fk', $column));
		}
		$this->table('rooms')
			->dropColumn('ceiling_material_id')
			->dropColumn('vault_spacing')
			->dropColumn('vault_angle')
			->dropColumn('vault_rise')
			->dropColumn('beam_width')
			->dropColumn('beam_drop')
			->dropColumn('beam_material_id')
			->dropColumn('skirting_height')
			->dropColumn('skirting_material_id')
			->alter();
	}
}
