<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\SavedView;
use const DATE_ATOM;

/** A saved view; `settings.variants` is an object of variant set id: group id. */
final readonly class SavedViewDto
{
	public function __construct(
		public int $id,
		public string $name,
		public int $floorId,
		public string $floorName,
		public SavedViewSettingsDto $settings,
		public ?string $createdByName,
		public string $updatedAt,
	) {
	}

	public static function fromEntity(SavedView $view, SavedViewSettingsDto $settings): self
	{
		return new self(
			id: $view->id,
			name: $view->name,
			floorId: $view->floor->id,
			floorName: $view->floor->name,
			settings: $settings,
			createdByName: $view->createdBy?->name,
			updatedAt: $view->updatedAt->format(DATE_ATOM),
		);
	}
}
