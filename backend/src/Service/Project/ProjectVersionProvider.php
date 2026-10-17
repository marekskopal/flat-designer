<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectVersion;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ProjectChangeRepository;
use FlatDesigner\Model\Repository\ProjectVersionRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;
use MarekSkopal\ORM\ORM;

/**
 * Saved versions of a project. The floors are the editable working copy; saving a version freezes a
 * snapshot of them, restoring one replaces the working copy (after auto-saving it as a version first,
 * so a restore can always be undone) and ends undo/redo of the edits before it.
 */
final readonly class ProjectVersionProvider
{
	private const int MaxNoteLength = 255;

	public function __construct(
		private ProjectVersionRepository $projectVersionRepository,
		private ProjectSnapshotService $snapshotService,
		private ProjectRevisionService $revisionService,
		private ProjectChangeRepository $changeRepository,
		private ORM $orm,
	) {
	}

	/** @return list<ProjectVersion> Newest first. */
	public function list(Project $project): array
	{
		return $this->projectVersionRepository->findByProject($project->id);
	}

	public function find(Project $project, int $versionId): ?ProjectVersion
	{
		return $this->projectVersionRepository->findOneInProject($project->id, $versionId);
	}

	public function create(User $author, Project $project, ?string $note): ProjectVersion
	{
		$note = trim($note ?? '');
		if (mb_strlen($note) > self::MaxNoteLength) {
			throw new ProjectException('Note is too long (at most 255 characters).');
		}

		$version = new ProjectVersion(
			project: $project,
			number: ($this->projectVersionRepository->findLatest($project->id)->number ?? 0) + 1,
			note: $note === '' ? null : $note,
			snapshot: $this->snapshotService->createSnapshot($project),
			createdBy: $author,
		);
		$version->createdAt = new DateTimeImmutable();
		$version->updatedAt = $version->createdAt;
		$this->projectVersionRepository->persist($version);

		return $version;
	}

	/** @return ProjectVersion the version auto-saved from the working copy before it was replaced */
	public function restore(User $author, Project $project, ProjectVersion $version): ProjectVersion
	{
		return $this->orm->getTransactionProvider()->transaction(function () use ($author, $project, $version): ProjectVersion {
			$backup = $this->create($author, $project, sprintf('Before restoring version %d', $version->number));
			$this->snapshotService->restore($project, $version->snapshot);
			// The logged changes refer to elements that are gone now.
			$this->changeRepository->closeAll($project->id);
			$this->revisionService->bump($project);

			return $backup;
		});
	}
}
