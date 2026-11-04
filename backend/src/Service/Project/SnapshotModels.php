<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

use Izbik\Model\Entity\ModelAsset;
use Izbik\Model\Entity\Project;
use Izbik\Model\Repository\ModelAssetRepository;

/**
 * The models of a snapshot from another instance: one uploaded with it (a project file's) by its new id, else matched by
 * its source URL (from the floors' `models`) among the global models and the project owner's library, a global one
 * first: the items take this instance's model id, none where there is no such model (the item then draws a box).
 * `make example-models` imports those of the examples.
 */
final readonly class SnapshotModels
{
	public function __construct(private ModelAssetRepository $modelRepository)
	{
	}

	/**
	 * @param array<mixed> $data the decoded snapshot
	 * @param array<int, int> $uploaded the id of a model uploaded with the snapshot by the snapshot's
	 * @return array<mixed>
	 */
	public function localize(array $data, Project $project, array $uploaded = []): array
	{
		$ids = $uploaded + $this->ids($data, $project);

		return SnapshotJson::mapFloors($data, static fn (array $entry): array => SnapshotJson::mapItems(
			$entry,
			static function (array $item) use ($ids): array {
				$modelId = $item['modelId'] ?? null;
				if (is_int($modelId)) {
					$item['modelId'] = $ids[$modelId] ?? null;
				}

				return $item;
			},
		));
	}

	/**
	 * @param array<mixed> $data
	 * @return array<int, int|null> this instance's model id by the snapshot's
	 */
	private function ids(array $data, Project $project): array
	{
		$bySource = $this->bySource($project);
		$ids = [];
		foreach (SnapshotJson::arrays($data['floors'] ?? null) as $entry) {
			foreach (SnapshotJson::arrays($entry['models'] ?? null) as $model) {
				$source = $model['sourceUrl'] ?? null;
				if (is_int($model['id'] ?? null)) {
					$ids[$model['id']] = is_string($source) ? ($bySource[$source] ?? null)?->id : null;
				}
			}
		}

		return $ids;
	}

	/** @return array<string, ModelAsset> the models the project may use, by source URL (a global one first) */
	public function bySource(Project $project): array
	{
		$owner = $project->owner;
		$bySource = [];
		foreach ($this->modelRepository->findVisible($owner === null ? [] : [$owner->id], null) as $model) {
			$current = $bySource[$model->sourceUrl] ?? null;
			if ($model->sourceUrl !== '' && ($current === null || $current->user !== null && $model->user === null)) {
				$bySource[$model->sourceUrl] = $model;
			}
		}

		return $bySource;
	}
}
