<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Site walls and outdoor stairs (FD-87): a wall's top may slope to `walls.height_end` at its end, and its bottom lie
 * at `base_start` / `base_end` or follow the ground (`on_ground`); a stair may have a `rise` of its own (it does not
 * climb to the next floor) and stand at `base_z`.
 */
final class AddSiteWallsAndStairsMigration extends Migration
{
	public function up(): void
	{
		$this->table('walls')
			->addColumn('height_end', Type::Int, nullable: true)
			->addColumn('base_start', Type::Int, default: 0)
			->addColumn('base_end', Type::Int, default: 0)
			->addColumn('on_ground', Type::Boolean, default: false)
			->alter();
		$this->table('stairs')
			->addColumn('rise', Type::Int, nullable: true)
			->addColumn('base_z', Type::Int, default: 0)
			->alter();
	}

	public function down(): void
	{
		$this->table('walls')
			->dropColumn('height_end')
			->dropColumn('base_start')
			->dropColumn('base_end')
			->dropColumn('on_ground')
			->alter();
		$this->table('stairs')
			->dropColumn('rise')
			->dropColumn('base_z')
			->alter();
	}
}
