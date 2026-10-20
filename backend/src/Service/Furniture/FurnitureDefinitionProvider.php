<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture;

use DateTimeImmutable;
use FlatDesigner\Dto\FurnitureDefinitionDto;
use FlatDesigner\Dto\FurnitureDefinitionInputDto;
use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\FurnitureDefinitionRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Realtime\ProjectUpdatePublisher;

/**
 * Furniture definitions and who may use them. Reading: global ones everyone; a user's library its owner and admins; a
 * project's definitions whoever may access the project. Writing: global ones admins only, otherwise as reading.
 * Deleting is soft (items using a definition keep rendering).
 */
final readonly class FurnitureDefinitionProvider
{
	private const int MaxNameLength = 255;

	private const int MaxDescriptionLength = 2000;

	public function __construct(
		private FurnitureDefinitionRepository $definitionRepository,
		private ProjectProvider $projectProvider,
		private DefinitionValidator $validator,
		private ItemRepository $itemRepository,
		private ProjectUpdatePublisher $updatePublisher,
		private ScopePolicy $scopePolicy,
	) {
	}

	/**
	 * What the user can pick: global ones and their library; with a project, what its items may use instead (`isUsableIn`):
	 * global ones, the project's own and its owner's library (an admin's own library is not among them — copy to the
	 * project).
	 *
	 * @return list<FurnitureDefinition>
	 */
	public function listVisible(User $user, ?Project $project): array
	{
		return $this->definitionRepository->findVisible(ScopePolicy::libraryOwnerIds($user, $project), $project?->id);
	}

	/** The definition when the user may read it and it is not deleted; otherwise null, as if it did not exist. */
	public function findReadable(User $user, int $definitionId): ?FurnitureDefinition
	{
		$definition = $this->definitionRepository->findById($definitionId);
		if ($definition === null || $definition->deletedAt !== null || !$this->canRead($user, $definition)) {
			return null;
		}

		return $definition;
	}

	public function canRead(User $user, FurnitureDefinition $definition): bool
	{
		return $this->scopePolicy->canRead($user, $definition);
	}

	/** Whether items of the project may use it: global ones, the project owner's library and the project's own. */
	public static function isUsableIn(FurnitureDefinition $definition, Project $project): bool
	{
		return ScopePolicy::isUsableIn($definition, $project);
	}

	public function canWrite(User $user, FurnitureDefinition $definition): bool
	{
		return $this->scopePolicy->canWrite($user, $definition);
	}

	public function create(User $author, FurnitureDefinitionInputDto $input): FurnitureDefinition
	{
		[$owner, $project] = $this->target($author, $input->scope ?? FurnitureScopeEnum::User, $input->projectId);
		$definition = new FurnitureDefinition(
			user: $owner,
			project: $project,
			name: self::name($input->name),
			description: self::description($input->description),
			definition: $this->validated($input->definition),
			createdBy: $author,
		);
		$definition->createdAt = new DateTimeImmutable();
		$definition->updatedAt = $definition->createdAt;
		$this->definitionRepository->persist($definition);

		return $definition;
	}

	/** Changes the name, description and definition; the scope stays (copy it to move it). */
	public function update(User $user, FurnitureDefinition $definition, FurnitureDefinitionInputDto $input): FurnitureDefinition
	{
		$this->assertWritable($user, $definition);
		if ($input->scope !== null || $input->projectId !== null) {
			throw new FurnitureException('The scope cannot be changed; copy the definition instead.');
		}
		$definition->name = self::name($input->name);
		$definition->description = self::description($input->description);
		$definition->definition = $this->validated($input->definition);
		$definition->updatedAt = new DateTimeImmutable();
		$this->definitionRepository->persist($definition);
		$this->updatePublisher->markDefinitionChanged($definition->id);

		return $definition;
	}

	public function delete(User $user, FurnitureDefinition $definition): void
	{
		$this->assertWritable($user, $definition);
		$definition->deletedAt = new DateTimeImmutable();
		$definition->updatedAt = $definition->deletedAt;
		$this->definitionRepository->persist($definition);
	}

	/** A new definition with the same content in another scope (to a project, into my library, global for admins). */
	public function copy(User $author, FurnitureDefinition $source, FurnitureScopeEnum $scope, ?int $projectId): FurnitureDefinition
	{
		[$owner, $project] = $this->target($author, $scope, $projectId);
		$copy = new FurnitureDefinition(
			user: $owner,
			project: $project,
			name: $source->name,
			description: $source->description,
			definition: $source->definition,
			createdBy: $author,
		);
		$copy->createdAt = new DateTimeImmutable();
		$copy->updatedAt = $copy->createdAt;
		$this->definitionRepository->persist($copy);

		return $copy;
	}

	/** @param bool $withUsage also count the items drawn from it (for a single definition, not lists) */
	public function toDto(User $user, FurnitureDefinition $definition, bool $withUsage = false): FurnitureDefinitionDto
	{
		return FurnitureDefinitionDto::fromEntity(
			$definition,
			$this->canWrite($user, $definition),
			$withUsage ? $this->itemRepository->countByDefinition($definition->id) : null,
		);
	}

	/** @return array{User|null, Project|null} the owners of a definition in `$scope` */
	private function target(User $author, FurnitureScopeEnum $scope, ?int $projectId): array
	{
		switch ($scope) {
			case FurnitureScopeEnum::Global:
				if (!$author->isAdmin()) {
					throw FurnitureException::forbidden('Only admins manage global furniture.');
				}

				return [null, null];
			case FurnitureScopeEnum::User:
				return [$author, null];
			default:
				$project = $projectId === null ? null : $this->projectProvider->findAccessible($author, $projectId);
				if ($project === null) {
					throw $projectId === null
						? new FurnitureException('Field "projectId" is required for project furniture.')
						: FurnitureException::notFound('Project not found.');
				}

				return [null, $project];
		}
	}

	private function assertWritable(User $user, FurnitureDefinition $definition): void
	{
		if (!$this->canWrite($user, $definition)) {
			throw FurnitureException::forbidden('Only admins manage global furniture.');
		}
	}

	/** @param array<mixed> $definition */
	private function validated(array $definition): string
	{
		try {
			return $this->validator->validate($definition)->json;
		} catch (DefinitionException $e) {
			throw new FurnitureException($e->getMessage(), previous: $e);
		}
	}

	private static function name(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MaxNameLength) {
			throw new FurnitureException(sprintf('Name is required (at most %d characters).', self::MaxNameLength));
		}

		return $name;
	}

	private static function description(string $description): string
	{
		$description = trim($description);
		if (mb_strlen($description) > self::MaxDescriptionLength) {
			throw new FurnitureException(sprintf('Description is at most %d characters.', self::MaxDescriptionLength));
		}

		return $description;
	}
}
