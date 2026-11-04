<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

use DateTimeImmutable;
use Izbik\Dto\ProjectImportDto;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Service\Project\Exception\InvalidSnapshotException;
use Izbik\Service\Project\ProjectFileProvider;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Project\ProjectSnapshotService;
use Izbik\Service\Project\SnapshotJson;
use MarekSkopal\ORM\ORM;
use Throwable;

/**
 * Makes a new project of the user's from a project file (`ProjectBundle`, opened by `ProjectBundleReader`), in one
 * transaction: the files first and the models no model of this instance stands in for (matched by source URL, as
 * `SnapshotModels` does), as the project's own under new ids; then the project's own definitions; then the snapshot
 * (`ProjectSnapshotService::import()`, with those ids); then the prices. What this instance cannot take of the files,
 * models, definitions and prices is left out and reported as a warning; a snapshot it cannot take fails the import.
 * Should it fail, the objects stored so far are deleted again (best effort), as the rows roll back.
 */
final readonly class ProjectImporter
{
	public function __construct(
		private ProjectBundleReader $reader,
		private ProjectRepository $projectRepository,
		private FloorRepository $floorRepository,
		private ProjectSnapshotService $snapshotService,
		private ProjectFileProvider $fileProvider,
		private BundleAssetImporter $assetImporter,
		private BundleLibraryImporter $libraryImporter,
		private ProjectProvider $projectProvider,
		private ORM $orm,
	) {
	}

	/** @param string $path the uploaded project file */
	public function import(User $owner, string $path): ProjectImportDto
	{
		$bundle = $this->reader->open($path);
		/** @var list<string> $stored storage keys written so far, deleted again should the import fail */
		$stored = [];
		/** @var list<string> $warnings */
		$warnings = [];

		try {
			$project = $this->orm->getTransactionProvider()->transaction(
				function () use ($owner, $bundle, &$stored, &$warnings): Project {
					return $this->create($owner, $bundle, $stored, $warnings);
				},
			);
		} catch (Throwable $e) {
			$this->fileProvider->deleteStoredObjects($stored);

			throw $e instanceof InvalidSnapshotException ? new ProjectImportException($e->getMessage(), previous: $e) : $e;
		} finally {
			$bundle->close();
		}

		$floors = $this->floorRepository->findByProject($project->id);
		$ground = array_values(array_filter($floors, static fn (Floor $floor): bool => $floor->level >= 0))[0] ?? $floors[0] ?? null;

		return new ProjectImportDto(project: $this->projectProvider->toDto($project), floorId: $ground?->id, warnings: $warnings);
	}

	/**
	 * @param list<string> $stored
	 * @param list<string> $warnings
	 */
	private function create(User $owner, OpenedBundle $bundle, array &$stored, array &$warnings): Project
	{
		$manifest = $bundle->manifest;
		$project = new Project(owner: $owner, name: 'Imported project', address: null, description: null);
		$project->createdAt = new DateTimeImmutable();
		$project->updatedAt = $project->createdAt;
		$this->projectRepository->persist($project);

		$assets = $this->assetImporter->upload($owner, $project, $bundle, $stored, $warnings);
		$this->libraryImporter->definitions($owner, $project, SnapshotJson::arrays($manifest['definitions'] ?? null), $warnings);
		$this->snapshotService->importDecoded($project, SnapshotJson::array($manifest, 'snapshot'), $assets);
		$this->libraryImporter->prices($project, SnapshotJson::array($manifest, 'pricing'), $warnings);

		return $project;
	}
}
