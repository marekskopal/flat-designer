<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\ProjectFile;
use const DATE_ATOM;

final readonly class ProjectFileDto
{
	public function __construct(
		public int $id,
		public string $filename,
		public string $mimeType,
		public int $size,
		public ?string $uploadedByName,
		public string $createdAt,
	) {
	}

	public static function fromEntity(ProjectFile $file): self
	{
		return new self(
			id: $file->id,
			filename: $file->filename,
			mimeType: $file->mimeType,
			size: $file->size,
			uploadedByName: $file->uploadedBy?->name,
			createdAt: $file->createdAt->format(DATE_ATOM),
		);
	}
}
