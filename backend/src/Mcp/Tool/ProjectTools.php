<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\ChangeDto;
use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Dto\FloorInputDto;
use FlatDesigner\Dto\ProjectDto;
use FlatDesigner\Dto\ProjectInputDto;
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

	private const array Clearable = ['address', 'description', 'location', 'timeZone', 'exteriorMaterialId'];

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
		description: 'Get a project: name, address, site (north angle, location, time zone), floors (ids, names, levels, elevations, slab thicknesses), revision, '
			. 'latest version number.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function getProject(int $projectId): ProjectDto
	{
		return $this->projectProvider->toDto($this->context->project($projectId));
	}

	/**
	 * @param string $name e.g. "Flat 12, Vinohrady"
	 * @param string|null $address street address of the building
	 * @param string|null $description free notes about the project
	 */
	#[McpTool(
		name: 'create_project',
		description: 'Create a project you own. It starts with one empty floor ("Ground floor", level 0); add more with add_floor.',
	)]
	public function createProject(string $name, ?string $address = null, ?string $description = null): ProjectDto
	{
		return $this->projectProvider->toDto(
			$this->projectProvider->create($this->context->user(), new ProjectInputDto($name, $address, $description)),
		);
	}

	/**
	 * @param string|null $address street address of the building; an empty string clears it, as `clear` does
	 * @param string|null $description free notes about the project; an empty string clears it, as `clear` does
	 * @param int|null $northAngle where true north lies, degrees clockwise from plan north (+y), 0–359
	 * @param float|null $latitude where the building stands, degrees north (south negative); with `longitude`
	 * @param float|null $longitude degrees east (west negative); with `latitude`
	 * @param string|null $timeZone IANA time zone of the site, e.g. "Europe/Prague" (the sun's clock time is read in it)
	 * @param int|null $exteriorMaterialId the façade (render, cladding) of the exterior walls' outer faces that have no
	 *   material or bands of their own, from list_materials
	 * @param list<string>|null $clear fields to set to none: "address", "description", "location" (latitude and longitude),
	 *   "timeZone", "exteriorMaterialId"
	 */
	#[McpTool(
		name: 'update_project',
		description: 'Change a project\'s name, address, description, site (the north angle and the location: latitude, '
			. 'longitude, time zone, which the viewer\'s sun is placed from) or default façade material. Only the given fields change.',
	)]
	public function updateProject(
		int $projectId,
		?string $name = null,
		?string $address = null,
		?string $description = null,
		?int $northAngle = null,
		?float $latitude = null,
		?float $longitude = null,
		?string $timeZone = null,
		?int $exteriorMaterialId = null,
		?array $clear = null,
	): ProjectDto {
		$project = $this->context->project($projectId);
		$cleared = array_keys(ToolContext::fields([], $clear, self::Clearable));
		$noLocation = in_array('location', $cleared, true);
		$input = new ProjectInputDto(
			name: $name ?? $project->name,
			address: in_array('address', $cleared, true) ? null : ($address ?? $project->address),
			description: in_array('description', $cleared, true) ? null : ($description ?? $project->description),
			northAngle: $northAngle ?? $project->northAngle,
			latitude: $noLocation ? null : ($latitude ?? $project->latitude),
			longitude: $noLocation ? null : ($longitude ?? $project->longitude),
			timeZone: in_array('timeZone', $cleared, true) ? null : ($timeZone ?? $project->timeZone),
			exteriorMaterialId: in_array(
				'exteriorMaterialId',
				$cleared,
				true,
			) ? null : ($exteriorMaterialId ?? $project->exteriorMaterial?->id),
		);

		return $this->projectProvider->toDto($this->projectProvider->update($project, $input));
	}

	#[McpTool(
		name: 'delete_project',
		description: 'Delete a project for good, with all its floors, versions, share links and files. This cannot be undone: '
			. 'ask the user to confirm first, naming the project.',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	/** @return ProjectDto the deleted project */
	public function deleteProject(int $projectId): ProjectDto
	{
		$project = $this->context->project($projectId);
		$deleted = $this->projectProvider->toDto($project);
		$this->projectProvider->delete($project);

		return $deleted;
	}

	/**
	 * @param string $name e.g. "2. NP" or "Basement"
	 * @param int $level storey order: 0 = ground floor, 1 = first floor, -1 = basement
	 * @param int|null $elevation mm, the finished floor level above the project datum ±0.000; by default the floor stands
	 *   on the tallest wall of the floor below (or hangs under the one above), plus its slab
	 * @param int|null $slabThickness mm, the slab under the floor (0–1000); by default 300 at level 0 and below, else 250
	 */
	#[McpTool(
		name: 'add_floor',
		description: 'Add an empty floor (storey) to a project. All floors share the plan coordinates, so draw its walls over '
			. 'those of the floor below.',
	)]
	public function addFloor(int $projectId, string $name, int $level, ?int $elevation = null, ?int $slabThickness = null): FloorDto
	{
		return FloorDto::fromEntity(
			$this->floorProvider->create($this->context->project($projectId), new FloorInputDto($name, $level, $elevation, $slabThickness)),
		);
	}

	/**
	 * @param string|null $name e.g. "2. NP"
	 * @param int|null $level storey order: 0 = ground floor, 1 = first floor, -1 = basement
	 * @param int|null $elevation mm, the finished floor level above the project datum ±0.000
	 * @param int|null $slabThickness mm, the slab under the floor (0–1000)
	 */
	#[McpTool(
		name: 'update_floor',
		description: 'Rename a floor or change its level, elevation or slab thickness. Only the given fields change; the '
			. 'other floors do not move.',
	)]
	public function updateFloor(
		int $projectId,
		int $floorId,
		?string $name = null,
		?int $level = null,
		?int $elevation = null,
		?int $slabThickness = null,
	): FloorDto {
		$floor = $this->context->floor($projectId, $floorId);

		return FloorDto::fromEntity(
			$this->floorProvider->update(
				$floor,
				new FloorInputDto($name ?? $floor->name, $level ?? $floor->level, $elevation, $slabThickness),
			),
		);
	}

	#[McpTool(
		name: 'delete_floor',
		description: 'Delete a floor with its rooms, walls and items. A project keeps at least one floor. Saved versions keep '
			. 'it, so a version restore brings it back; ask the user to confirm first.',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	/** @return FloorDto the deleted floor */
	public function deleteFloor(int $projectId, int $floorId): FloorDto
	{
		$floor = $this->context->floor($projectId, $floorId);
		$deleted = FloorDto::fromEntity($floor);
		$this->floorProvider->delete($floor);

		return $deleted;
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
