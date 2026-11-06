<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Zones over part of a room: `rooms.floor_zones`, JSON `[{polygon, materialId}]` (another floor material over part of
 * the floor), and `rooms.ceiling_zones`, JSON `[{polygon, height, materialId}]` (a dropped ceiling down to `height` mm
 * above the room's floor); null: none.
 */
final class AddRoomZonesMigration extends Migration
{
	public function up(): void
	{
		$this->table('rooms')
			->addColumn('floor_zones', Type::Text, nullable: true)
			->addColumn('ceiling_zones', Type::Text, nullable: true)
			->alter();
	}

	public function down(): void
	{
		$this->table('rooms')
			->dropColumn('floor_zones')
			->dropColumn('ceiling_zones')
			->alter();
	}
}
