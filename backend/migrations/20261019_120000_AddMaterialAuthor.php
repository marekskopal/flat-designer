<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/** Who added a material (admins over MCP, `MaterialService`); seeded materials have none. */
final class AddMaterialAuthorMigration extends Migration
{
	public function up(): void
	{
		$this->table('materials')
			->addColumn('created_by_id', Type::Int, size: 11, nullable: true)
			->addForeignKey('created_by_id', 'users', 'id', 'materials_created_by_id_fk', ReferenceOptionEnum::SetNull)
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec(
			'ALTER TABLE materials DROP FOREIGN KEY materials_created_by_id_fk, DROP KEY materials_created_by_id_fk, DROP COLUMN created_by_id',
		);
	}
}
