<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\ChangeDto;
use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Dto\ProjectDto;
use FlatDesigner\Dto\ProjectVersionDto;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectChange;
use FlatDesigner\Model\Entity\ProjectVersion;
use FlatDesigner\Model\Repository\ProjectChangeRepository;
use FlatDesigner\Service\Project\FloorProvider;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Project\ProjectVersionProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

final readonly class ProjectTools
{
	private const int MaxChanges = 100;

	public function __construct(
		private ToolContext $context,
		private ProjectProvider $projectProvider,
		private FloorProvider $floorProvider,
		private ProjectVersionProvider $versionProvider,
		private ProjectChangeRepository $changeRepository,
	) {
	}

	/** @return list<ProjectDto> */
	#[McpTool(
		name: 'list_projects',
		description: 'List the projects you can edit, with their floors and current revision.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listProjects(): array
	{
		return array_map(
			fn (Project $project): ProjectDto => $this->projectProvider->toDto($project),
			$this->projectProvider->listAccessible($this->context->user()),
		);
	}

	#[McpTool(
		name: 'get_project',
		description: 'Get a project: name, address, floors (ids, names, levels), revision, latest version number.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getProject(int $projectId): ProjectDto
	{
		return $this->projectProvider->toDto($this->context->project($projectId));
	}

	/**
	 * @param string $name e.g. "2. NP" or "Basement"
	 * @param int $level storey order: 0 = ground floor, 1 = first floor, -1 = basement
	 */
	#[McpTool(name: 'add_floor', description: 'Add an empty floor (storey) to a project.')]
	public function addFloor(int $projectId, string $name, int $level): FloorDto
	{
		return FloorDto::fromEntity($this->floorProvider->create($this->context->project($projectId), new FloorInputDto($name, $level)));
	}

	/** @return list<ProjectVersionDto> */
	#[McpTool(
		name: 'list_versions',
		description: 'List the saved versions of a project, newest first.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listVersions(int $projectId): array
	{
		return array_map(
			fn (ProjectVersion $version): ProjectVersionDto => ProjectVersionDto::fromEntity($version),
			$this->versionProvider->list($this->context->project($projectId)),
		);
	}

	/** @param string|null $note what this version is, e.g. "Before moving the kitchen island" */
	#[McpTool(
		name: 'save_version',
		description: 'Save a version (checkpoint) of all floors of the project, which the user can view and restore later. Do this before a larger redesign.',
	)]
	public function saveVersion(int $projectId, ?string $note = null): ProjectVersionDto
	{
		return ProjectVersionDto::fromEntity(
			$this->versionProvider->create($this->context->user(), $this->context->project($projectId), $note),
		);
	}

	/**
	 * @param int $limit how many of the newest changes (max 100)
	 * @return list<ChangeDto>
	 */
	#[McpTool(
		name: 'list_changes',
		description: 'Recent changes of the project (by the user and by agents), newest first.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listChanges(int $projectId, int $limit = 20): array
	{
		return array_map(
			fn (ProjectChange $change): ChangeDto => ChangeDto::fromEntity($change),
			$this->changeRepository->findRecent($this->context->project($projectId)->id, max(1, min($limit, self::MaxChanges))),
		);
	}
}
