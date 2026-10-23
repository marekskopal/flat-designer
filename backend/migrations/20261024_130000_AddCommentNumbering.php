<?php

declare(strict_types=1);

namespace Migrations;

use MarekSkopal\ORM\Enum\Type;
use MarekSkopal\ORM\Migrations\Migration\Migration;

/**
 * Thread numbers counted by the floor (`floors.last_comment_number`, `CommentNumbering`) instead of the highest number
 * still in `comments`, so a deleted thread's number is never given again, and unique per floor. Numbers given twice so
 * far (two threads posted at once, two floors merged by a version restore) are renumbered above the floor's highest.
 */
final class AddCommentNumberingMigration extends Migration
{
	public function up(): void
	{
		$this->table('floors')
			->addColumn('last_comment_number', Type::Int, default: 0)
			->alter();

		$pdo = $this->databaseProvider->getDatabase()->getPdo();
		$pdo->exec('UPDATE floors f SET last_comment_number = COALESCE((SELECT MAX(c.number) FROM comments c WHERE c.floor_id = f.id), 0)');

		$duplicates = $pdo->query(
			'SELECT DISTINCT c.id, c.floor_id FROM comments c '
			. 'JOIN comments d ON d.floor_id = c.floor_id AND d.number = c.number AND d.id < c.id ORDER BY c.id',
		);
		$bump = $pdo->prepare('UPDATE floors SET last_comment_number = last_comment_number + 1 WHERE id = ?');
		$renumber = $pdo->prepare('UPDATE comments SET number = (SELECT last_comment_number FROM floors WHERE id = ?) WHERE id = ?');
		if ($duplicates === false || $bump === false || $renumber === false) {
			throw new \RuntimeException('Could not prepare the thread renumbering.');
		}
		/** @var array{id: int, floor_id: int} $duplicate */
		foreach ($duplicates->fetchAll() as $duplicate) {
			$bump->execute([$duplicate['floor_id']]);
			$renumber->execute([$duplicate['floor_id'], $duplicate['id']]);
		}

		$this->table('comments')
			->addIndex(['floor_id', 'number'], 'comments_floor_id_number_unique', true)
			->alter();
	}

	public function down(): void
	{
		$this->table('comments')
			->dropIndex('comments_floor_id_number_unique')
			->alter();
		$this->table('floors')
			->dropColumn('last_comment_number')
			->alter();
	}
}
