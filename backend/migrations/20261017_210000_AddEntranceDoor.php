<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Marks the main door of a floor (`openings.entrance`), where the walkthrough starts; flat 50's
 * entrance door from the common corridor gets the flag.
 */
final class AddEntranceDoorMigration extends Migration
{
	public function up(): void
	{
		$this->table('openings')
			->addColumn('entrance', Type::Boolean, default: false)
			->alter();

		$this->databaseProvider->getDatabase()->getPdo()->exec(
			"UPDATE openings o JOIN walls w ON w.id = o.wall_id SET o.entrance = 1
			WHERE o.type = 'Door' AND w.type = 'Exterior' AND o.name = 'Entrance door'",
		);
	}

	public function down(): void
	{
		$this->table('openings')
			->dropColumn('entrance')
			->alter();
	}
}
