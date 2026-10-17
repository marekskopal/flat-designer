<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Realtime\ProjectUpdatePublisher;
use MarekSkopal\ORM\Database\DatabaseInterface;

/**
 * The project's revision counter. Bumping it is an atomic UPDATE, which also locks the project row until
 * the surrounding transaction ends, so concurrent edits of one project run one after another. A bump also
 * marks the project for the live update sent after the request.
 */
final readonly class ProjectRevisionService
{
	public function __construct(private DatabaseInterface $database, private ProjectUpdatePublisher $updatePublisher)
	{
	}

	/**
	 * @param int|null $baseRevision the revision the client edited; null skips the check (MCP, internal)
	 * @return int the new revision
	 */
	public function bump(Project $project, ?int $baseRevision = null): int
	{
		$now = new DateTimeImmutable();
		$sql = 'UPDATE projects SET revision = revision + 1, updated_at = ? WHERE id = ?';
		$params = [$now->format('Y-m-d H:i:s'), $project->id];
		if ($baseRevision !== null) {
			$sql .= ' AND revision = ?';
			$params[] = $baseRevision;
		}

		if ($this->database->execute($sql, $params, cached: false)->rowCount() !== 1) {
			throw EditException::conflict('The project was changed in the meantime; reload and try again.');
		}

		$revision = $this->database->execute('SELECT revision FROM projects WHERE id = ?', [$project->id], cached: false)->fetchColumn();
		$project->revision = is_numeric($revision) ? (int) $revision : $project->revision + 1;
		$project->updatedAt = $now;
		$this->updatePublisher->markChanged($project->id, $project->revision);

		return $project->revision;
	}
}
