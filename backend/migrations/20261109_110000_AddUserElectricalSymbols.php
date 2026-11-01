<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** A user's symbol set for the electrical plan (FD-64). */
final class AddUserElectricalSymbolsMigration extends Migration
{
	public function up(): void
	{
		$this->table('users')
			->addColumn('electrical_symbols', Type::Enum, enum: ['Iec', 'Csn'], default: 'Iec')
			->alter();
	}

	public function down(): void
	{
		$this->table('users')
			->dropColumn('electrical_symbols')
			->alter();
	}
}
