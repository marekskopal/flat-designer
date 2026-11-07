<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Room type `Kitchen`: indoor, counted in the living area like a living room; a new one has no default floor. */
final class AddKitchenRoomTypeMigration extends Migration
{
	private const array RoomTypes = ['Living', 'Bedroom', 'Bathroom', 'Toilet', 'Hallway', 'Terrace', 'Garden', 'Path', 'Driveway', 'Garage'];

	public function up(): void
	{
		$this->table('rooms')
			->alterColumn('type', Type::Enum, enum: [...self::RoomTypes, 'Kitchen'])
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("UPDATE rooms SET type = 'Living' WHERE type = 'Kitchen'");
		$this->table('rooms')
			->alterColumn('type', Type::Enum, enum: self::RoomTypes)
			->alter();
	}
}
