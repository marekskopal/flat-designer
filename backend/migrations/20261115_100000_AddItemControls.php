<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Switch-to-light links of the electrical plan: `items.controls`, the ids of the lights a switch item controls (JSON). */
final class AddItemControlsMigration extends Migration
{
	public function up(): void
	{
		$this->table('items')
			->addColumn('controls', Type::Text, nullable: true)
			->alter();
	}

	public function down(): void
	{
		$this->table('items')
			->dropColumn('controls')
			->alter();
	}
}
