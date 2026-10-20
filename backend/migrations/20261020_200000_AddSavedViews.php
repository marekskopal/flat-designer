<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Saved views: a named camera and display of one floor of the working copy (`settings` is `SavedViewSettingsDto`
 * JSON), e.g. for the README screenshots. They go with their floor; a version restore recreates them from its snapshot.
 */
final class AddSavedViewsMigration extends Migration
{
	public function up(): void
	{
		$this->table('saved_views')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('project_id', Type::Int, size: 11)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('name', Type::String)
			->addColumn('settings', Type::Text)
			->addColumn('created_by_id', Type::Int, size: 11, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['project_id'], 'saved_views_project_id_index', false)
			->addIndex(['floor_id'], 'saved_views_floor_id_index', false)
			->addForeignKey('project_id', 'projects', 'id', 'saved_views_project_id_fk')
			->addForeignKey('floor_id', 'floors', 'id', 'saved_views_floor_id_fk')
			->addForeignKey('created_by_id', 'users', 'id', 'saved_views_created_by_id_fk', ReferenceOptionEnum::SetNull)
			->create();
	}

	public function down(): void
	{
		$this->table('saved_views')->drop();
	}
}
