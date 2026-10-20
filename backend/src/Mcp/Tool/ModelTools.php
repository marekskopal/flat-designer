<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Service\ModelAsset\ModelAssetProvider;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/** The 3D models of bought products that Model items draw. Users upload them in the app; agents list and place them. */
final readonly class ModelTools
{
	public function __construct(private ToolContext $context, private ModelAssetProvider $modelProvider)
	{
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
			. 'An item scales its model evenly to fit its box; give it the model\'s proportions. Models are uploaded by users in '
			. 'the app (Furniture page), not over MCP.',
		annotations: new ToolAnnotations(readOnlyHint: true),
	)]
	public function listModels(?int $projectId = null): array
	{
		$project = $projectId === null ? null : $this->context->project($projectId);

		return array_map(static fn (ModelAsset $model): array => [
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
		], $this->modelProvider->listVisible($this->context->user(), $project));
	}
}
