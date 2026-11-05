<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** A user's language; existing accounts keep English. */
final class AddUserLocaleMigration extends Migration
{
	public function up(): void
	{
		$this->table('users')
			->addColumn('locale', Type::Enum, enum: ['en', 'cs'], default: 'en')
			->alter();
	}

	public function down(): void
	{
		$this->table('users')
			->dropColumn('locale')
			->alter();
	}
}
