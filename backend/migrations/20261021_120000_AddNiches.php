<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Opening type `Niche`: a recess `depth` mm deep into the `side` face of its wall (null for other types). */
final class AddNichesMigration extends Migration
{
	private const array Types = ['Door', 'DoubleDoor', 'SlidingDoor', 'PocketDoor', 'Passage', 'Window'];

	public function up(): void
	{
		$this->table('openings')
			->alterColumn('type', Type::Enum, enum: [...self::Types, 'Niche'])
			->addColumn('depth', Type::Int, nullable: true)
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("DELETE FROM openings WHERE type = 'Niche'");
		$this->table('openings')
			->alterColumn('type', Type::Enum, enum: self::Types)
			->dropColumn('depth')
			->alter();
	}
}
