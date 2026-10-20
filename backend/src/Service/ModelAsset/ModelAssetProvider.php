<?php

declare(strict_types=1);

namespace FlatDesigner\Service\ModelAsset;

use DateTimeImmutable;
use FlatDesigner\Dto\ModelAssetInputDto;
use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ModelAssetRepository;
use FlatDesigner\Service\Furniture\ScopePolicy;
use FlatDesigner\Service\Project\ProjectFileProvider;
use FlatDesigner\Service\Project\ProjectProvider;
use FlatDesigner\Service\Storage\FileStorageInterface;
use FlatDesigner\Service\Storage\S3Config;
use Throwable;

/**
 * 3D models (glTF binaries) and who may use them, with the same rules as furniture definitions: global ones everyone
 * reads and admins write, a user's library its owner and admins, a project's models whoever may access the project.
 * Items of a project may use global models, the project owner's library and the project's own. The file is stored once
 * and never changes; deleting is soft, so items keep drawing it.
 */
final readonly class ModelAssetProvider
{
	private const int MaxNameLength = 255;
	private const int MaxDescriptionLength = 2000;
	private const int MaxCreditLength = 255;
	private const int MaxSourceLength = 512;

	public function __construct(
		private ModelAssetRepository $modelRepository,
		private ProjectProvider $projectProvider,
		private FileStorageInterface $fileStorage,
		private S3Config $s3Config,
		private ScopePolicy $scopePolicy,
	) {
	}

	/**
	 * What the user can pick: global ones and their library; with a project, what its items may use instead.
	 *
	 * @return list<ModelAsset>
	 */
	public function listVisible(User $user, ?Project $project): array
	{
		return $this->modelRepository->findVisible(ScopePolicy::libraryOwnerIds($user, $project), $project?->id);
	}

	/** The model when the user may read it and it is not deleted; otherwise null, as if it did not exist. */
	public function findReadable(User $user, int $modelId): ?ModelAsset
	{
		$model = $this->modelRepository->findById($modelId);

		return $model === null || $model->deletedAt !== null || !$this->canRead($user, $model) ? null : $model;
	}

	public function canRead(User $user, ModelAsset $model): bool
	{
		return $this->scopePolicy->canRead($user, $model);
	}

	public function canWrite(User $user, ModelAsset $model): bool
	{
		return $this->scopePolicy->canWrite($user, $model);
	}

	/** Whether items of the project may use it: global ones, the project owner's library and the project's own. */
	public static function isUsableIn(ModelAsset $model, Project $project): bool
	{
		return ScopePolicy::isUsableIn($model, $project);
	}

	/** Stores a new model from the bytes of a `.glb` file; its size is read from the file. */
	public function upload(?User $author, ModelAssetInputDto $input, string $filename, string $bytes): ModelAsset
	{
		if (strlen($bytes) > $this->s3Config->maxFileSizeBytes) {
			throw new ModelAssetException(
				sprintf('The model is larger than %d MB.', intdiv($this->s3Config->maxFileSizeBytes, 1024 * 1024)),
				413,
			);
		}
		$size = GlbReader::size($bytes);
		[$owner, $project] = $this->target($author, $input->scope ?? FurnitureScopeEnum::User, $input->projectId);

		$model = new ModelAsset(
			user: $owner,
			project: $project,
			name: self::text($input->name, self::MaxNameLength, 'Name', true),
			description: self::text($input->description, self::MaxDescriptionLength, 'Description'),
			filename: self::filename($filename),
			storageKey: sprintf('models/%s.glb', bin2hex(random_bytes(16))),
			size: strlen($bytes),
			width: $size['width'],
			depth: $size['depth'],
			height: $size['height'],
			license: self::text($input->license, self::MaxCreditLength, 'License'),
			author: self::text($input->author, self::MaxCreditLength, 'Author'),
			sourceUrl: self::sourceUrl($input->sourceUrl),
			createdBy: $author,
		);
		$model->createdAt = new DateTimeImmutable();
		$model->updatedAt = $model->createdAt;
		$this->modelRepository->persist($model);

		try {
			$this->fileStorage->put($model->storageKey, $bytes, 'model/gltf-binary');
		} catch (Throwable $e) {
			$this->modelRepository->delete($model);

			throw $e;
		}

		return $model;
	}

	/** Changes the describing fields; the scope and the file stay (upload a new model to replace it). */
	public function update(User $user, ModelAsset $model, ModelAssetInputDto $input): ModelAsset
	{
		$this->assertWritable($user, $model);
		if ($input->scope !== null || $input->projectId !== null) {
			throw new ModelAssetException('The scope of a model cannot be changed.');
		}
		$model->name = self::text($input->name, self::MaxNameLength, 'Name', true);
		$model->description = self::text($input->description, self::MaxDescriptionLength, 'Description');
		$model->license = self::text($input->license, self::MaxCreditLength, 'License');
		$model->author = self::text($input->author, self::MaxCreditLength, 'Author');
		$model->sourceUrl = self::sourceUrl($input->sourceUrl);
		$model->updatedAt = new DateTimeImmutable();
		$this->modelRepository->persist($model);

		return $model;
	}

	public function delete(User $user, ModelAsset $model): void
	{
		$this->assertWritable($user, $model);
		$model->deletedAt = new DateTimeImmutable();
		$model->updatedAt = $model->deletedAt;
		$this->modelRepository->persist($model);
	}

	public function readContent(ModelAsset $model): string
	{
		return $this->fileStorage->get($model->storageKey);
	}

	/** @return array{User|null, Project|null} the owners of a model in `$scope` */
	private function target(?User $author, FurnitureScopeEnum $scope, ?int $projectId): array
	{
		if ($scope === FurnitureScopeEnum::Global) {
			if ($author !== null && !$author->isAdmin()) {
				throw new ModelAssetException('Only admins manage global models.', 403);
			}

			return [null, null];
		}
		if ($author === null) {
			throw new ModelAssetException('A library or project model needs an author.');
		}
		if ($scope === FurnitureScopeEnum::User) {
			return [$author, null];
		}
		$project = $projectId === null ? null : $this->projectProvider->findAccessible($author, $projectId);
		if ($project === null) {
			throw $projectId === null
				? new ModelAssetException('Field "projectId" is required for a project model.')
				: new ModelAssetException('Project not found.', 404);
		}

		return [null, $project];
	}

	private function assertWritable(User $user, ModelAsset $model): void
	{
		if (!$this->canWrite($user, $model)) {
			throw new ModelAssetException('Only an admin (global models) or the owner may change a model.', 403);
		}
	}

	private static function text(string $value, int $maxLength, string $label, bool $required = false): string
	{
		$value = trim($value);
		if ($required && $value === '') {
			throw new ModelAssetException(sprintf('%s is required.', $label));
		}
		if (mb_strlen($value) > $maxLength) {
			throw new ModelAssetException(sprintf('%s is at most %d characters.', $label, $maxLength));
		}

		return $value;
	}

	/** Empty, or an http(s) address (credits link to where the model came from). */
	private static function sourceUrl(string $url): string
	{
		$url = trim($url);
		if ($url !== '' && (mb_strlen($url) > self::MaxSourceLength || preg_match('~^https?://\S+$~', $url) !== 1)) {
			throw new ModelAssetException('Source must be an http(s) address.');
		}

		return $url;
	}

	private static function filename(string $filename): string
	{
		return trim($filename) === '' ? 'model.glb' : ProjectFileProvider::cleanFilename($filename);
	}
}
