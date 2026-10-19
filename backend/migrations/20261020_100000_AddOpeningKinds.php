<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Opening types `DoubleDoor`, `SlidingDoor`, `PocketDoor` and `Passage`, and the door's `frame_style` and
 * `handle` (null: the type's default handle).
 */
final class AddOpeningKindsMigration extends Migration
{
	public function up(): void
	{
		$this->table('openings')
			->alterColumn('type', Type::Enum, enum: ['Door', 'DoubleDoor', 'SlidingDoor', 'PocketDoor', 'Passage', 'Window'])
			->addColumn('frame_style', Type::Enum, enum: ['Lining', 'Architrave', 'Flush', 'None'], default: 'Lining')
			->addColumn('handle', Type::Enum, enum: ['Lever', 'Knob', 'Pull', 'None'], nullable: true)
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("UPDATE openings SET type = 'Door' WHERE type <> 'Window'");
		$this->table('openings')
			->alterColumn('type', Type::Enum, enum: ['Door', 'Window'])
			->dropColumn('frame_style')
			->dropColumn('handle')
			->alter();
	}
}
