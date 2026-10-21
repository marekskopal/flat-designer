<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Wall finish bands per wall face (JSON like `rooms.wall_finishes`), overriding the room's; null takes the room's. */
final class AddWallFaceFinishesMigration extends Migration
{
	public function up(): void
	{
		$this->table('walls')
			->addColumn('left_finishes', Type::Text, nullable: true)
			->addColumn('right_finishes', Type::Text, nullable: true)
			->alter();
	}

	public function down(): void
	{
		$this->table('walls')
			->dropColumn('right_finishes')
			->dropColumn('left_finishes')
			->alter();
	}
}
