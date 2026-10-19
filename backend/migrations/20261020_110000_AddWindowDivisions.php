<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Window glazing: `mullions` (vertical bars; 1, two panes, is how windows were drawn so far), `transoms`
 * (horizontal bars) and `sill_board` (the inner sill board's reach past the wall face, mm; null: none).
 */
final class AddWindowDivisionsMigration extends Migration
{
	public function up(): void
	{
		$this->table('openings')
			->addColumn('mullions', Type::Int, default: 1)
			->addColumn('transoms', Type::Int, default: 0)
			->addColumn('sill_board', Type::Int, nullable: true)
			->alter();
	}

	public function down(): void
	{
		$this->table('openings')
			->dropColumn('mullions')
			->dropColumn('transoms')
			->dropColumn('sill_board')
			->alter();
	}
}
