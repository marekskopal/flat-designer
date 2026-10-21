<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\ModelAssetInputDto;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Service\ModelAsset\ModelAssetProvider;
use FlatDesigner\Service\Upload\UploadTicketService;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use RuntimeException;

/** The 3D models of bought products that Model items draw: list, upload and delete them. */
final readonly class ModelTools
{
	private const string ScopeHelp = 'scope "user" (default) adds it to your library, "project" to the project projectId only, '
		. '"global" for everyone (admins only).';

	public function __construct(
		private ToolContext $context,
		private ModelAssetProvider $modelProvider,
		private UploadTicketService $uploadTicketService,
	) {
	}

	/**
	 * @param int|null $projectId with a project: what its items may use (global, the project's own, the project owner's
	 *     library); without: global ones and your library
	 * @return list<array<string, mixed>>
	 */
	#[McpTool(
		name: 'list_models',
		description: '3D models of bought products (chairs, lamps, taps, plants, …) for items of kind "Model" (add_item with '
			. 'modelId): name, description, scope, native size in mm (width across, depth front to back, height) and credits. '
			. 'An item scales its model evenly to fit its box; give it the model\'s proportions. Add a model with '
			. 'request_model_upload (or upload_model for a small one).',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listModels(?int $projectId = null): array
	{
		$project = $projectId === null ? null : $this->context->project($projectId);

		return array_map(self::toArray(...), $this->modelProvider->listVisible($this->context->user(), $project));
	}

	/**
	 * @param string $content the `.glb` file, base64 encoded
	 * @param string $scope "user", "project" or "global"
	 * @param int|null $projectId the project of a "project" model
	 * @param string $license e.g. CC0, for a third-party model
	 * @param string $sourceUrl an http(s) address where the model came from
	 * @return array<string, mixed>
	 */
	#[McpTool(
		name: 'upload_model',
		description: 'Upload a small 3D model (a self-contained glTF binary, .glb) with its content as base64. Only for files '
			. 'up to a few hundred kB: the whole content has to be written into the call. For a larger file you can read '
			. 'from a shell, use request_model_upload. ' . self::ScopeHelp . ' The size in mm is read from the model (glTF '
			. 'metres). Draco and Basis compression are rejected (use meshopt and WebP), as are models with separate files.',
	)]
	public function uploadModel(
		string $name,
		string $content,
		string $filename = 'model.glb',
		string $description = '',
		string $license = '',
		string $author = '',
		string $sourceUrl = '',
		string $scope = 'user',
		?int $projectId = null,
	): array {
		$input = self::input($name, $description, $license, $author, $sourceUrl, $scope, $projectId);
		$bytes = UploadContent::decodeBase64($content);

		return self::toArray($this->modelProvider->upload($this->context->user(), $input, $filename, $bytes));
	}

	/**
	 * @param string $scope "user", "project" or "global"
	 * @param int|null $projectId the project of a "project" model
	 * @param string $license e.g. CC0, for a third-party model
	 * @param string $sourceUrl an http(s) address where the model came from
	 * @return array{uploadUrl: string, expiresAt: string, curl: string}
	 */
	#[McpTool(
		name: 'request_model_upload',
		description: 'A one-time URL to upload a 3D model (a self-contained .glb) from a disk you can reach from a shell. The '
			. 'name, credits and scope are checked now; send the file as the raw body of a PUT, e.g. with the returned curl '
			. 'command. The answer is the stored model as JSON (id, size in mm), or an error saying what was rejected '
			. '(size, not a .glb, Draco or Basis compression, separate files). ' . self::ScopeHelp . ' The URL works once, '
			. 'for 15 minutes; a rejected file may be sent again to the same URL.',
	)]
	public function requestModelUpload(
		string $name,
		string $filename = 'model.glb',
		string $description = '',
		string $license = '',
		string $author = '',
		string $sourceUrl = '',
		string $scope = 'user',
		?int $projectId = null,
	): array {
		[$ticket, $token] = $this->uploadTicketService->createForModel(
			$this->context->user(),
			self::input($name, $description, $license, $author, $sourceUrl, $scope, $projectId),
			$filename,
		);

		return UploadContent::uploadUrl($this->context->baseUrl(), $token, $ticket->expiresAt);
	}

	/** @return array<string, mixed> the deleted model */
	#[McpTool(
		name: 'delete_model',
		description: 'Delete a model (global ones only admins). Items already drawing it keep it; it can no longer be added.',
		annotations: new ToolAnnotations(destructiveHint: true),
	)]
	public function deleteModel(int $modelId): array
	{
		$user = $this->context->user();
		$model = $this->modelProvider->findReadable($user, $modelId)
			?? throw new RuntimeException(sprintf('Model %d not found. Use list_models.', $modelId));
		$this->modelProvider->delete($user, $model);

		return self::toArray($model);
	}

	private static function input(
		string $name,
		string $description,
		string $license,
		string $author,
		string $sourceUrl,
		string $scope,
		?int $projectId,
	): ModelAssetInputDto {
		return new ModelAssetInputDto(
			name: $name,
			description: $description,
			license: $license,
			author: $author,
			sourceUrl: $sourceUrl,
			scope: ToolContext::scope($scope),
			projectId: $projectId,
		);
	}

	/** @return array<string, mixed> */
	private static function toArray(ModelAsset $model): array
	{
		return [
			'id' => $model->id,
			'name' => $model->name,
			'description' => $model->description,
			'scope' => $model->scope()->value,
			'projectId' => $model->project?->id,
			'width' => $model->width,
			'depth' => $model->depth,
			'height' => $model->height,
			'license' => $model->license,
			'author' => $model->author,
			'sourceUrl' => $model->sourceUrl,
		];
	}
}
