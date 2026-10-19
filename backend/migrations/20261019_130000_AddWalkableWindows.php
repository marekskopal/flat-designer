<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Windows down to the floor that can be walked through (`openings.walkable`): their sashes open in the
 * walkthrough, into the opening's `side`. Flat 50's French windows to the terrace and garden get the flag;
 * the sashes open indoors: left of the south façade (the default), right of the north one (terrace).
 */
final class AddWalkableWindowsMigration extends Migration
{
	public function up(): void
	{
		$this->table('openings')
			->addColumn('walkable', Type::Boolean, default: false)
			->alter();

		$this->databaseProvider->getDatabase()->getPdo()->exec(
			"UPDATE openings o JOIN walls w ON w.id = o.wall_id SET o.walkable = 1
			WHERE o.type = 'Window' AND o.sill_height = 0 AND w.type = 'Exterior' AND o.name LIKE '%French window%'",
		);
		$this->databaseProvider->getDatabase()->getPdo()->exec(
			"UPDATE openings SET side = 'Right' WHERE walkable = 1 AND name LIKE '%French window to terrace'",
		);
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("UPDATE openings SET side = NULL WHERE type = 'Window' AND walkable = 1");
		$this->table('openings')
			->dropColumn('walkable')
			->alter();
	}
}
