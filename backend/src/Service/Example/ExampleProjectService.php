<?php

declare(strict_types=1);

namespace Izbik\Service\Example;

use DateTimeImmutable;
use Izbik\Dto\ExampleProjectDto;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\SavedView;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Model\Repository\ProjectRepository;
use Izbik\Model\Repository\SavedViewRepository;
use Izbik\Service\Project\ProjectProvider;
use Izbik\Service\Project\ProjectSnapshotService;
use MarekSkopal\ORM\ORM;

/** Copies an example project into a user's account: a new project of theirs holding the example's floors and views. */
final readonly class ExampleProjectService
{
	public function __construct(
		private ProjectRepository $projectRepository,
		private FloorRepository $floorRepository,
		private SavedViewRepository $viewRepository,
		private ProjectSnapshotService $snapshotService,
		private ProjectProvider $projectProvider,
		private ORM $orm,
	) {
	}

	public function create(User $owner, Example $example): ExampleProjectDto
	{
		$project = $this->orm->getTransactionProvider()->transaction(function () use ($owner, $example): Project {
			$project = new Project(owner: $owner, name: $example->name, address: null, description: null);
			$project->createdAt = new DateTimeImmutable();
			$project->updatedAt = $project->createdAt;
			$this->projectRepository->persist($project);
			$this->snapshotService->import($project, $example->snapshot);

			return $project;
		});

		$view = $this->startView($project, $example);
		$floors = $this->floorRepository->findByProject($project->id);
		$ground = array_values(array_filter($floors, static fn (Floor $floor): bool => $floor->level >= 0))[0] ?? $floors[0] ?? null;

		return new ExampleProjectDto(
			project: $this->projectProvider->toDto($project),
			floorId: $view?->floor->id ?? $ground?->id,
			viewId: $view?->id,
		);
	}

	private function startView(Project $project, Example $example): ?SavedView
	{
		foreach ($example->startView === null ? [] : $this->viewRepository->findByProject($project->id) as $view) {
			if ($view->name === $example->startView) {
				return $view;
			}
		}

		return null;
	}
}
