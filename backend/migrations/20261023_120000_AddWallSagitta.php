<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * `walls.sagitta`: a curved wall's centre line, mm from the chord's midpoint to the arc (+ to the left of start → end).
 * 0 keeps every existing wall straight.
 */
final class AddWallSagittaMigration extends Migration
{
	public function up(): void
	{
		$this->table('walls')
			->addColumn('sagitta', Type::Int, default: 0)
			->alter();
	}

	public function down(): void
	{
		$this->table('walls')
			->dropColumn('sagitta')
			->alter();
	}
}
