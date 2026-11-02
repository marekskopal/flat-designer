<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Skewed verges: a gable's or shed's verge at the ridge's start and end turned by degrees, 0 square. */
final class AddRoofSkewsMigration extends Migration
{
	public function up(): void
	{
		$this->table('roofs')
			->addColumn('start_skew', Type::Int, default: 0)
			->addColumn('end_skew', Type::Int, default: 0)
			->alter();
	}

	public function down(): void
	{
		$this->table('roofs')
			->dropColumn('start_skew')
			->dropColumn('end_skew')
			->alter();
	}
}
