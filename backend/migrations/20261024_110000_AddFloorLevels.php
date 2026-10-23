<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Levels (FD-39): `rooms.floor_elevation` raises or lowers a room's floor against the floor's level (a terrace a step
 * down, a raised deck), `plots.ground_level` the ground of a plot. Both mm, 0 keeps them where they were.
 */
final class AddFloorLevelsMigration extends Migration
{
	public function up(): void
	{
		$this->table('rooms')
			->addColumn('floor_elevation', Type::Int, default: 0)
			->alter();
		$this->table('plots')
			->addColumn('ground_level', Type::Int, default: 0)
			->alter();
	}

	public function down(): void
	{
		$this->table('rooms')
			->dropColumn('floor_elevation')
			->alter();
		$this->table('plots')
			->dropColumn('ground_level')
			->alter();
	}
}
