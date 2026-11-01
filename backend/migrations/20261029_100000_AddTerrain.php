<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Sloping terrain (FD-86): `plots.terrain`, the plot's spot heights as JSON `[[x, y, z], …]` (plan mm, z against the
 * floor's level; null: the ground is flat at `ground_level`), and `items.on_ground`, an item whose z counts from the
 * ground under its centre.
 */
final class AddTerrainMigration extends Migration
{
	public function up(): void
	{
		$this->table('plots')
			->addColumn('terrain', Type::Text, nullable: true)
			->alter();
		$this->table('items')
			->addColumn('on_ground', Type::Boolean, default: false)
			->alter();
	}

	public function down(): void
	{
		$this->table('plots')
			->dropColumn('terrain')
			->alter();
		$this->table('items')
			->dropColumn('on_ground')
			->alter();
	}
}
