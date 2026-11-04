<?php

declare(strict_types=1);

namespace Izbik\Service\Project\Transfer;

use Izbik\Dto\ModelAssetInputDto;
use Izbik\Model\Entity\Enum\FurnitureScopeEnum;
use Izbik\Model\Entity\ModelAsset;
use Izbik\Model\Entity\Project;
use Izbik\Model\Entity\User;
use Izbik\Service\ModelAsset\ModelAssetException;
use Izbik\Service\ModelAsset\ModelAssetProvider;
use Izbik\Service\Project\Exception\ProjectException;
use Izbik\Service\Project\ImportedAssets;
use Izbik\Service\Project\ProjectFileProvider;
use Izbik\Service\Project\SnapshotJson;
use Izbik\Service\Project\SnapshotModels;

/**
 * Uploads what a project file brings along into the new project (`ProjectImporter`): every file, and the models no model
 * of this instance stands in for (matched by source URL, as `SnapshotModels` does), as the project's own. One this
 * instance does not take is left out with a warning; the storage keys written go into `$stored`.
 */
final readonly class BundleAssetImporter
{
	public function __construct(
		private ProjectFileProvider $fileProvider,
		private ModelAssetProvider $modelProvider,
		private SnapshotModels $snapshotModels,
	) {
	}

	/**
	 * @param list<string> $stored
	 * @param list<string> $warnings
	 */
	public function upload(User $owner, Project $project, OpenedBundle $bundle, array &$stored, array &$warnings): ImportedAssets
	{
		return new ImportedAssets(
			fileIds: $this->files($owner, $project, $bundle, $stored, $warnings),
			modelIds: $this->models($owner, $project, $bundle, $stored, $warnings),
		);
	}

	/**
	 * @param list<string> $stored
	 * @param list<string> $warnings
	 * @return array<int, int> the new file's id by the bundle's
	 */
	private function files(User $owner, Project $project, OpenedBundle $bundle, array &$stored, array &$warnings): array
	{
		$ids = [];
		foreach (SnapshotJson::arrays($bundle->manifest['files'] ?? null) as $file) {
			$id = $file['id'] ?? null;
			$path = $file['path'] ?? null;
			$filename = is_string($file['filename'] ?? null) ? $file['filename'] : 'file';
			$bytes = is_string($path) ? $bundle->entry($path) : null;
			if (!is_int($id) || $bytes === null) {
				$warnings[] = sprintf('File "%s" was left out: it is missing from the project file or too large.', $filename);

				continue;
			}

			try {
				$uploaded = $this->fileProvider->upload($owner, $project, $filename, $bytes);
			} catch (ProjectException $e) {
				$warnings[] = sprintf('File "%s" was left out: %s', $filename, $e->getMessage());

				continue;
			}

			$stored[] = $uploaded->storageKey;
			$ids[$id] = $uploaded->id;
		}

		return $ids;
	}

	/**
	 * @param list<string> $stored
	 * @param list<string> $warnings
	 * @return array<int, int> the new model's id by the bundle's, for those uploaded
	 */
	private function models(User $owner, Project $project, OpenedBundle $bundle, array &$stored, array &$warnings): array
	{
		$known = $this->snapshotModels->bySource($project);
		$ids = [];
		foreach (SnapshotJson::arrays($bundle->manifest['models'] ?? null) as $model) {
			$id = $model['id'] ?? null;
			$sourceUrl = self::text($model, 'sourceUrl');
			if (!is_int($id) || $sourceUrl !== '' && isset($known[$sourceUrl])) {
				continue;
			}

			try {
				$uploaded = $this->uploadModel($owner, $project, $bundle, $model);
			} catch (ModelAssetException $e) {
				$warnings[] = sprintf('Model "%s" was left out: %s', self::text($model, 'name'), $e->getMessage());

				continue;
			}

			$stored[] = $uploaded->storageKey;
			$ids[$id] = $uploaded->id;
		}

		return $ids;
	}

	/** @param array<mixed> $model the bundle's entry */
	private function uploadModel(User $owner, Project $project, OpenedBundle $bundle, array $model): ModelAsset
	{
		$path = $model['path'] ?? null;
		$bytes = is_string($path) ? $bundle->entry($path) : null;
		if ($bytes === null) {
			throw new ModelAssetException('it is missing from the project file or too large.');
		}
		$filename = self::text($model, 'filename');

		return $this->modelProvider->upload($owner, new ModelAssetInputDto(
			name: self::text($model, 'name'),
			description: self::text($model, 'description'),
			license: self::text($model, 'license'),
			author: self::text($model, 'author'),
			sourceUrl: self::text($model, 'sourceUrl'),
			scope: FurnitureScopeEnum::Project,
			projectId: $project->id,
		), $filename === '' ? 'model.glb' : $filename, $bytes);
	}

	/** @param array<mixed> $data */
	private static function text(array $data, string $key): string
	{
		return is_string($data[$key] ?? null) ? $data[$key] : '';
	}
}
