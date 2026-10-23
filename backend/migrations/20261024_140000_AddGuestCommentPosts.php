<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * When a share link's guests posted comments, for their hourly limit (`GuestCommentLog`). Kept apart from `comments`,
 * so deleting the comments (or a version restore dropping them) does not let the link post again. It starts with the
 * guest comments of the last hour, so the limit carries on over the migration.
 */
final class AddGuestCommentPostsMigration extends Migration
{
	public function up(): void
	{
		$this->table('guest_comment_posts')
			->addColumn('id', Type::Int, autoincrement: true, primary: true)
			->addColumn('share_link_id', Type::Int, size: 11)
			->addColumn('created_at', Type::Timestamp)
			->addIndex(['share_link_id', 'created_at'], 'guest_comment_posts_share_link_id_created_at_index', false)
			->addForeignKey('share_link_id', 'share_links', 'id', 'guest_comment_posts_share_link_id_fk')
			->create();

		$this->databaseProvider->getDatabase()->getPdo()->exec(
			'INSERT INTO guest_comment_posts (share_link_id, created_at) '
			. 'SELECT share_link_id, created_at FROM comments '
			. 'WHERE share_link_id IS NOT NULL AND created_at >= NOW() - INTERVAL 1 HOUR ORDER BY id',
		);
	}

	public function down(): void
	{
		$this->table('guest_comment_posts')->drop();
	}
}
