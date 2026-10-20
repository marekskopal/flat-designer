<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\ModelAsset;
use const DATE_ATOM;

/**
 * A model as the scene carries it for the `Model` items that draw it: native size to scale it by and its credits. The
 * viewer loads the file by id; `updatedAt` changes when it is replaced.
 */
final readonly class SceneModelDto
{
	public function __construct(
		public int $id,
		public string $name,
		public int $width,
		public int $depth,
		public int $height,
		public string $license,
		public string $author,
		public string $sourceUrl,
		public string $updatedAt,
	) {
	}

	public static function fromEntity(ModelAsset $model): self
	{
		return new self(
			id: $model->id,
			name: $model->name,
			width: $model->width,
			depth: $model->depth,
			height: $model->height,
			license: $model->license,
			author: $model->author,
			sourceUrl: $model->sourceUrl,
			updatedAt: $model->updatedAt->format(DATE_ATOM),
		);
	}
}
