<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\ModelAssetRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\ModelAsset\ModelAssetProvider;

/**
 * The 3D model of a `Model` item, for `ItemHandler`: required for that kind and usable in the floor's project (global,
 * the project owner's library or the project's own); other kinds have none. A deleted model is still accepted, so undo
 * and copies of items using it keep working.
 */
final readonly class ItemModelLink
{
	public function __construct(private ModelAssetRepository $modelRepository)
	{
	}

	public function find(mixed $modelId): ?ModelAsset
	{
		return is_int($modelId) ? $this->modelRepository->findById($modelId) : null;
	}

	/** For a restore: the model of that id while it exists and the project may use it, otherwise none (the item then draws a box). */
	public function forProject(Project $project, ?int $modelId): ?ModelAsset
	{
		$model = $modelId === null ? null : $this->modelRepository->findById($modelId);

		return $model !== null && ModelAssetProvider::isUsableIn($model, $project) ? $model : null;
	}

	/** The model id the item keeps: the given one for a `Model` item, none for other kinds. */
	public function validate(Floor $floor, ItemKindEnum $kind, ?int $modelId): ?int
	{
		if ($kind !== ItemKindEnum::Model) {
			return null;
		}
		if ($modelId === null) {
			throw EditException::invalid('A Model item needs a modelId (see list_models).');
		}
		$model = $this->modelRepository->findById($modelId);
		if ($model === null || !ModelAssetProvider::isUsableIn($model, $floor->project)) {
			throw EditException::invalid(sprintf(
				'Model %d is not available in this project (global, the project owner\'s or the project\'s own).',
				$modelId,
			));
		}

		return $modelId;
	}
}
