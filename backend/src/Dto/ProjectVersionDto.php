<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\ProjectVersion;
use const DATE_ATOM;

final readonly class ProjectVersionDto
{
	public function __construct(
		public int $id,
		public int $number,
		public ?string $note,
		public ?string $createdByName,
		public string $createdAt,
	) {
	}

	public static function fromEntity(ProjectVersion $version): self
	{
		return new self(
			id: $version->id,
			number: $version->number,
			note: $version->note,
			createdByName: $version->createdBy?->name,
			createdAt: $version->createdAt->format(DATE_ATOM),
		);
	}
}
