<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Comment;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\ShareLink;
use MarekSkopal\ORM\Database\DatabaseInterface;

/**
 * When a share link's guests posted comments (`guest_comment_posts`), for their hourly limit. It is kept apart from
 * the comments, so deleting them, or a version restore dropping them, does not let the link post again.
 */
final readonly class GuestCommentLog
{
	public function __construct(private DatabaseInterface $database)
	{
	}

	/**
	 * Logs a post through the link unless `$perHour` were logged in the last hour. Call it in a transaction: it locks the
	 * project row (as every write of a project does first, so nothing waits in a circle) until the transaction ends, so
	 * posts through one link are counted one after another.
	 */
	public function admit(ShareLink $link, int $perHour): bool
	{
		$now = new DateTimeImmutable();
		$hourAgo = $now->modify('-1 hour')->format('Y-m-d H:i:s');
		$this->database->execute('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$link->project->id], cached: false);
		$this->database->execute(
			'DELETE FROM guest_comment_posts WHERE share_link_id = ? AND created_at < ?',
			[$link->id, $hourAgo],
			cached: false,
		);
		$count = $this->database->execute('SELECT COUNT(*) FROM guest_comment_posts WHERE share_link_id = ?', [$link->id], cached: false)
			->fetchColumn();
		if (is_numeric($count) && (int) $count >= $perHour) {
			return false;
		}
		$this->database->execute(
			'INSERT INTO guest_comment_posts (share_link_id, created_at) VALUES (?, ?)',
			[$link->id, $now->format('Y-m-d H:i:s')],
			cached: false,
		);

		return true;
	}
}
