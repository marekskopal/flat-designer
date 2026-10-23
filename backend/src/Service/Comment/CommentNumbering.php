<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Comment;

use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Service\Project\Exception\ProjectException;
use LogicException;
use MarekSkopal\ORM\Database\DatabaseInterface;

/**
 * Thread numbers, counted up per floor in `floors.last_comment_number`, so a deleted thread's number is never given
 * again. The column is not mapped on `Floor`: persisting a floor would write back a stale count.
 *
 * @phpstan-type FloorNumbering array{level: int, floorName: string, lastNumber: int}
 */
final readonly class CommentNumbering
{
	public function __construct(private DatabaseInterface $database)
	{
	}

	/**
	 * The floor's next thread number. Call it in a transaction: it locks the project row (first, as the editor's writes
	 * do, so a comment and an edit never wait for each other) and then the floor row until the transaction ends, so
	 * threads posted at once on a floor are numbered one after another.
	 */
	public function next(Floor $floor): int
	{
		if (!$this->database->getPdo()->inTransaction()) {
			throw new LogicException('Threads are numbered in a transaction, which keeps the numbering locked.');
		}
		$this->database->execute('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$floor->project->id], cached: false);
		$updated = $this->database->execute(
			'UPDATE floors SET last_comment_number = last_comment_number + 1 WHERE id = ?',
			[$floor->id],
			cached: false,
		)->rowCount();
		if ($updated !== 1) {
			throw new ProjectException('The floor no longer exists.', 404);
		}
		$last = $this->database->execute('SELECT last_comment_number FROM floors WHERE id = ?', [$floor->id], cached: false)
			->fetchColumn();
		if (!is_numeric($last)) {
			throw new ProjectException('The floor no longer exists.', 404);
		}

		return (int) $last;
	}

	/** @return list<FloorNumbering> the last thread number given on each floor of the project */
	public function floorNumbering(int $projectId): array
	{
		$rows = $this->database->execute(
			'SELECT level, name, last_comment_number FROM floors WHERE project_id = ? ORDER BY id',
			[$projectId],
			cached: false,
		)->fetchAll();
		$numbering = [];
		foreach ($rows as $row) {
			if (!is_array($row) || !is_numeric($row['level'] ?? null) || !is_string($row['name'] ?? null)) {
				throw new LogicException('Unexpected floor row.');
			}
			$last = $row['last_comment_number'] ?? null;
			$numbering[] = [
				'level' => (int) $row['level'],
				'floorName' => $row['name'],
				'lastNumber' => is_numeric($last) ? (int) $last : 0,
			];
		}

		return $numbering;
	}

	/** Continues the floor's numbering after `$number` (a version restore carrying threads over). */
	public function continueAfter(Floor $floor, int $number): void
	{
		$this->database->execute(
			'UPDATE floors SET last_comment_number = GREATEST(last_comment_number, ?) WHERE id = ?',
			[$number, $floor->id],
			cached: false,
		);
	}
}
