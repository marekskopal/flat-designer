<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/** One-time upload URLs for MCP agents (a project file or a 3D model), stored as token hashes. */
final class AddUploadTicketsMigration extends Migration
{
	public function up(): void
	{
		$this->table('upload_tickets')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('token_hash', Type::String, size: 64)
			->addColumn('user_id', Type::Int, size: 11)
			->addColumn('kind', Type::Enum, enum: ['ProjectFile', 'Model'])
			->addColumn('project_id', Type::Int, size: 11, nullable: true)
			->addColumn('filename', Type::String)
			->addColumn('metadata', Type::Text)
			->addColumn('expires_at', Type::Timestamp)
			->addColumn('used_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['token_hash'], 'upload_tickets_token_hash_index', true)
			->addIndex(['expires_at'], 'upload_tickets_expires_at_index', false)
			->addForeignKey('user_id', 'users', 'id', 'upload_tickets_user_id_fk')
			->addForeignKey('project_id', 'projects', 'id', 'upload_tickets_project_id_fk')
			->create();
	}

	public function down(): void
	{
		$this->table('upload_tickets')->drop();
	}
}
