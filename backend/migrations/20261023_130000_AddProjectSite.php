<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Where a project's building stands, for the sun: `north_angle` (degrees clockwise from plan north, +y, to true north),
 * `latitude` / `longitude` (degrees, both or neither) and `time_zone` (IANA name the sun's clock time is read in).
 */
final class AddProjectSiteMigration extends Migration
{
	public function up(): void
	{
		$this->table('projects')
			->addColumn('north_angle', Type::Int, default: 0)
			->addColumn('latitude', Type::Double, nullable: true)
			->addColumn('longitude', Type::Double, nullable: true)
			->addColumn('time_zone', Type::String, size: 64, nullable: true)
			->alter();
	}

	public function down(): void
	{
		$this->table('projects')
			->dropColumn('north_angle')
			->dropColumn('latitude')
			->dropColumn('longitude')
			->dropColumn('time_zone')
			->alter();
	}
}
