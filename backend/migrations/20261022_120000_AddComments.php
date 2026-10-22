<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;
use MarekSkopal\ORM\Migrations\Migration\Query\Enum\ReferenceOptionEnum;

/**
 * Review comments pinned to a point of a floor (plan mm, `z` above the floor), with one level of replies. A thread has a
 * `number`, counted up per floor, which the viewers show on its pin. They go with their floor; a version restore
 * carries them over to the restored floors (`CommentKeeper`).
 */
final class AddCommentsMigration extends Migration
{
	public function up(): void
	{
		$this->table('comments')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('project_id', Type::Int, size: 11)
			->addColumn('floor_id', Type::Int, size: 11)
			->addColumn('parent_id', Type::Int, size: 11, nullable: true)
			->addColumn('number', Type::Int, nullable: true)
			->addColumn('x', Type::Int)
			->addColumn('y', Type::Int)
			->addColumn('z', Type::Int)
			->addColumn('body', Type::Text)
			->addColumn('author_type', Type::Enum, enum: ['User', 'Agent', 'Guest'])
			->addColumn('created_by_id', Type::Int, size: 11, nullable: true)
			->addColumn('mcp_client_id', Type::String, size: 128, nullable: true)
			->addColumn('mcp_client_name', Type::String, nullable: true)
			->addColumn('guest_name', Type::String, size: 100, nullable: true)
			->addColumn('share_link_id', Type::Int, size: 11, nullable: true)
			->addColumn('resolved_at', Type::Timestamp, nullable: true)
			->addColumn('created_at', Type::Timestamp)
			->addColumn('updated_at', Type::Timestamp)
			->addIndex(['project_id'], 'comments_project_id_index', false)
			->addIndex(['floor_id'], 'comments_floor_id_index', false)
			->addIndex(['share_link_id'], 'comments_share_link_id_index', false)
			->addForeignKey('project_id', 'projects', 'id', 'comments_project_id_fk')
			->addForeignKey('floor_id', 'floors', 'id', 'comments_floor_id_fk')
			->addForeignKey('parent_id', 'comments', 'id', 'comments_parent_id_fk')
			->addForeignKey('created_by_id', 'users', 'id', 'comments_created_by_id_fk', ReferenceOptionEnum::SetNull)
			->addForeignKey('share_link_id', 'share_links', 'id', 'comments_share_link_id_fk', ReferenceOptionEnum::SetNull)
			->create();
	}

	public function down(): void
	{
		$this->table('comments')->drop();
	}
}
