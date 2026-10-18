<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Share links: a public, view-only link to one floor of a saved version (created with the link). `floor_id` is
 * the floor's id inside the version snapshot, so it has no foreign key, and `floor_name` is kept with it;
 * `settings` is JSON (`ShareSettingsDto`).
 */
final class AddShareLinksMigration extends Migration
{
	public function up(): void
	{
		$this->table('share_links')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('project_id', Type::Int, size: 11)
			->addColumn('version_id', Type::Int, size: 11)
			->addColumn('floor_id', Type::Int)
			->addColumn('floor_name', Type::String)
			->addColumn('token', Type::String, size: 64)
			->addColumn('name', Type::String)
			->addColumn('settings', Type::Text)
			->addColumn('expires_at', Type::Timestamp, nullable: true)
			->addColumn('created_by_id', Type::Int, size: 11, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['token'], 'share_links_token_unique', true)
			->addIndex(['project_id'], 'share_links_project_id_index', false)
			->addForeignKey('project_id', 'projects', 'id', 'share_links_project_id_fk')
			->addForeignKey('version_id', 'project_versions', 'id', 'share_links_version_id_fk')
			->addForeignKey('created_by_id', 'users', 'id', 'share_links_created_by_id_fk', ReferenceOptionEnum::SetNull)
			->create();
	}

	public function down(): void
	{
		$this->table('share_links')->drop();
	}
}
