<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\FloorDto;
use FlatDesigner\Dto\ProjectDto;
use FlatDesigner\Dto\ProjectInputDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\ModelAssetRepository;
use FlatDesigner\Model\Repository\ProjectRepository;
use FlatDesigner\Model\Repository\ProjectVersionRepository;
use FlatDesigner\Service\Project\Exception\ProjectException;
use FlatDesigner\Service\Realtime\ProjectUpdatePublisher;
use MarekSkopal\ORM\ORM;

/** Projects and who may see them: the owner, and admins (all projects). */
final readonly class ProjectProvider
{
	private const int MaxNameLength = 255;

	public function __construct(
		private ProjectRepository $projectRepository,
		private FloorRepository $floorRepository,
		private ProjectVersionRepository $projectVersionRepository,
		private ProjectFileProvider $projectFileProvider,
		private ModelAssetRepository $modelRepository,
		private ProjectUpdatePublisher $updatePublisher,
		private ORM $orm,
	) {
	}

	/** The project when the user may access it; otherwise null, as if it did not exist. */
	public function findAccessible(User $user, int $projectId): ?Project
	{
		$project = $this->projectRepository->findById($projectId);
		if ($project === null || !$user->isAdmin() && $project->owner?->id !== $user->id) {
			return null;
		}

		return $project;
	}

	/** @return list<Project> Most recently changed first; admins get every project. */
	public function listAccessible(User $user): array
	{
		return $user->isAdmin() ? $this->projectRepository->findAllOrdered() : $this->projectRepository->findByOwner($user->id);
	}

	/**
	 * New project with one empty ground floor, so it has 1..N floors from the start.
	 */
	public function create(User $owner, ProjectInputDto $input): Project
	{
		$project = new Project(owner: $owner, name: self::name($input->name), address: null, description: null);
		self::applyDetails($project, $input);

		return $this->orm->getTransactionProvider()->transaction(function () use ($project): Project {
			$this->projectRepository->persist(self::stamp($project));
			$this->floorRepository->persist(self::stamp(new Floor(project: $project, name: 'Ground floor', level: 0)));

			return $project;
		});
	}

	/** Unchanged details are not a change: nothing is saved or announced. */
	public function update(Project $project, ProjectInputDto $input): Project
	{
		$before = [$project->name, $project->address, $project->description];
		$project->name = self::name($input->name);
		self::applyDetails($project, $input);
		if ($before !== [$project->name, $project->address, $project->description]) {
			$this->touch($project);
			$this->updatePublisher->markDetailsChanged($project->id);
		}

		return $project;
	}

	/** Floors with their content, versions, file and model rows go with it (ON DELETE CASCADE); then the stored files. */
	public function delete(Project $project): void
	{
		$storageKeys = [
			...$this->projectFileProvider->storageKeys($project), ...$this->modelRepository->storageKeysOfProject($project->id)];
		$this->projectRepository->delete($project);
		$this->projectFileProvider->deleteStoredObjects($storageKeys);
	}

	/** Marks the project as changed, e.g. after its floors were edited. */
	public function touch(Project $project): void
	{
		$project->updatedAt = new DateTimeImmutable();
		$this->projectRepository->persist($project);
	}

	public function toDto(Project $project): ProjectDto
	{
		return ProjectDto::fromEntity(
			$project,
			array_map(fn (Floor $floor): FloorDto => FloorDto::fromEntity($floor), $this->floorRepository->findByProject($project->id)),
			$this->projectVersionRepository->findLatest($project->id)?->number,
		);
	}

	private static function name(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MaxNameLength) {
			throw new ProjectException('Project name is required (at most 255 characters).');
		}

		return $name;
	}

	private static function applyDetails(Project $project, ProjectInputDto $input): void
	{
		$address = trim($input->address ?? '');
		$description = trim($input->description ?? '');
		if (mb_strlen($address) > self::MaxNameLength) {
			throw new ProjectException('Address is too long (at most 255 characters).');
		}

		$project->address = $address === '' ? null : $address;
		$project->description = $description === '' ? null : $description;
	}

	/**
	 * @param T $entity
	 * @return T
	 * @template T of Project|Floor
	 */
	private static function stamp(Project|Floor $entity): Project|Floor
	{
		$now = new DateTimeImmutable();
		$entity->createdAt = $now;
		$entity->updatedAt = $now;

		return $entity;
	}
}
