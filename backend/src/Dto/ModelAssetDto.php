<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\FurnitureScopeEnum;
use FlatDesigner\Model\Entity\ModelAsset;
use const DATE_ATOM;

/** A model in the library: its scope, native size (mm), file and credits, and whether the user may change it. */
final readonly class ModelAssetDto
{
	public function __construct(
		public int $id,
		public string $name,
		public string $description,
		public FurnitureScopeEnum $scope,
		public ?int $userId,
		public ?int $projectId,
		public string $filename,
		public int $size,
		public int $width,
		public int $depth,
		public int $height,
		public string $license,
		public string $author,
		public string $sourceUrl,
		public bool $writable,
		public string $updatedAt,
	) {
	}

	public static function fromEntity(ModelAsset $model, bool $writable): self
	{
		return new self(
			id: $model->id,
			name: $model->name,
			description: $model->description,
			scope: $model->scope(),
			userId: $model->user?->id,
			projectId: $model->project?->id,
			filename: $model->filename,
			size: $model->size,
			width: $model->width,
			depth: $model->depth,
			height: $model->height,
			license: $model->license,
			author: $model->author,
			sourceUrl: $model->sourceUrl,
			writable: $writable,
			updatedAt: $model->updatedAt->format(DATE_ATOM),
		);
	}
}
