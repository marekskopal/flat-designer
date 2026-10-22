<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * `rooms.floor_angle`: the floor's pattern (planks, tiles) turned about the room's tile origin, degrees
 * counter-clockwise (0–179). 0 keeps the planks along plan y, as before.
 */
final class AddRoomFloorAngleMigration extends Migration
{
	public function up(): void
	{
		$this->table('rooms')
			->addColumn('floor_angle', Type::Int, default: 0)
			->alter();
	}

	public function down(): void
	{
		$this->table('rooms')
			->dropColumn('floor_angle')
			->alter();
	}
}
