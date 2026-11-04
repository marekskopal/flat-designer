<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** Upload URLs for a project file to import as a new project (MCP `request_project_import`). */
final class AddProjectImportUploadsMigration extends Migration
{
	public function up(): void
	{
		$this->table('upload_tickets')
			->alterColumn('kind', Type::Enum, enum: ['ProjectFile', 'Model', 'ProjectImport'])
			->alter();
	}

	public function down(): void
	{
		$this->databaseProvider->getDatabase()->getPdo()->exec("DELETE FROM upload_tickets WHERE kind = 'ProjectImport'");
		$this->table('upload_tickets')
			->alterColumn('kind', Type::Enum, enum: ['ProjectFile', 'Model'])
			->alter();
	}
}
