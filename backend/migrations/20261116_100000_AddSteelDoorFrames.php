<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Door frame style `Steel`: a pressed steel frame wrapping the reveal, with a rebate for the leaf. */
final class AddSteelDoorFramesMigration extends Migration
{
	public function up(): void
	{
		$this->table('openings')
			->alterColumn('frame_style', Type::Enum, enum: ['Lining', 'Architrave', 'Flush', 'None', 'Steel'], default: 'Lining')
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("UPDATE openings SET frame_style = 'Lining' WHERE frame_style = 'Steel'");
		$this->table('openings')
			->alterColumn('frame_style', Type::Enum, enum: ['Lining', 'Architrave', 'Flush', 'None'], default: 'Lining')
			->alter();
	}
}
