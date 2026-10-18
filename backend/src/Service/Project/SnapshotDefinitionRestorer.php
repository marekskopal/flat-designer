<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\FurnitureDefinitionRepository;
use FlatDesigner\Service\Furniture\DefinitionValidator;
use FlatDesigner\Service\Furniture\FurnitureDefinitionProvider;

/**
 * The furniture definitions of a restored version (`definitions` of its floors, see ProjectSnapshotService): the live
 * definition of the same id while the project may use it (also a soft-deleted one, as the items used it until now),
 * otherwise a new project definition made from the copy stored in the version — or none when the version holds no
 * usable copy.
 */
final readonly class SnapshotDefinitionRestorer
{
	public function __construct(private FurnitureDefinitionRepository $definitionRepository)
	{
	}

	/**
	 * @param list<SnapshotReader> $entries
	 * @param array<int, FurnitureDefinition|null> $known definitions by snapshot id from earlier floors
	 * @return array<int, FurnitureDefinition|null> `$known` with those of `$entries` added
	 */
	public function restore(Project $project, array $entries, array $known): array
	{
		foreach ($entries as $data) {
			$id = $data->int('id');
			if (!array_key_exists($id, $known)) {
				$known[$id] = $this->live($project, $id) ?? $this->copy($project, $data);
			}
		}

		return $known;
	}

	private function live(Project $project, int $id): ?FurnitureDefinition
	{
		$definition = $this->definitionRepository->findById($id);

		return $definition !== null && FurnitureDefinitionProvider::isUsableIn($definition, $project) ? $definition : null;
	}

	private function copy(Project $project, SnapshotReader $data): ?FurnitureDefinition
	{
		$body = $data->raw('definition');
		if (!is_array($body)) {
			return null;
		}

		$copy = new FurnitureDefinition(
			user: null,
			project: $project,
			name: $data->string('name'),
			description: 'Restored from a version.',
			definition: DefinitionValidator::encode($body),
			createdBy: null,
		);
		$copy->createdAt = new DateTimeImmutable();
		$copy->updatedAt = $copy->createdAt;
		$this->definitionRepository->persist($copy);

		return $copy;
	}
}
