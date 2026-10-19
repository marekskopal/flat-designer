<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Door leaves get a `leaf_style` (Flush so far) with `leaf_panels` panels or panes; windows a solid lower
 * `panel_height` (mm from the sill; null: glass down to the bottom rail) and exterior `shutters` (null: none) with
 * their material (null: painted timber) and `shutters_closed`.
 */
final class AddLeafStylesAndShuttersMigration extends Migration
{
	public function up(): void
	{
		$this->table('openings')
			->addColumn('leaf_style', Type::Enum, enum: ['Flush', 'Panelled', 'Glazed'], default: 'Flush')
			->addColumn('leaf_panels', Type::Int, default: 2)
			->addColumn('panel_height', Type::Int, nullable: true)
			->addColumn('shutters', Type::Enum, enum: ['Louvred', 'Solid'], nullable: true)
			->addColumn('shutter_material_id', Type::Int, size: 11, nullable: true)
			->addColumn('shutters_closed', Type::Boolean, default: false)
			->addForeignKey('shutter_material_id', 'materials', 'id', 'openings_shutter_material_id_fk', ReferenceOptionEnum::SetNull)
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec(
			'ALTER TABLE openings DROP FOREIGN KEY openings_shutter_material_id_fk, DROP KEY openings_shutter_material_id_fk',
		);
		$this->table('openings')
			->dropColumn('leaf_style')
			->dropColumn('leaf_panels')
			->dropColumn('panel_height')
			->dropColumn('shutters')
			->dropColumn('shutter_material_id')
			->dropColumn('shutters_closed')
			->alter();
	}
}
