<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/** Files uploaded to a project; the content is in object storage (S3/MinIO) under `storage_key`. */
final class AddProjectFilesMigration extends Migration
{
	public function up(): void
	{
		$this->table('project_files')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('project_id', Type::Int, size: 11)
			->addColumn('filename', Type::String)
			->addColumn('mime_type', Type::String, size: 191)
			->addColumn('size', Type::Int)
			->addColumn('storage_key', Type::String, size: 512)
			->addColumn('uploaded_by_id', Type::Int, size: 11, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['project_id'], 'project_files_project_id_index', false)
			->addForeignKey('project_id', 'projects', 'id', 'project_files_project_id_fk')
			->addForeignKey('uploaded_by_id', 'users', 'id', 'project_files_uploaded_by_id_fk', ReferenceOptionEnum::SetNull)
			->create();
	}

	public function down(): void
	{
		$this->table('project_files')->drop();
	}
}
