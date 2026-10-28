<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\RoofWindow;

final readonly class RoofWindowDto
{
	public function __construct(
		public int $id,
		public string $name,
		public string $type,
		public int $along,
		public int $across,
		public int $width,
		public int $length,
		public ?int $frameMaterialId,
	) {
	}

	public static function fromEntity(RoofWindow $window): self
	{
		return new self(
			id: $window->id,
			name: $window->name,
			type: $window->type->value,
			along: $window->along,
			across: $window->across,
			width: $window->width,
			length: $window->length,
			frameMaterialId: $window->frameMaterial?->id,
		);
	}
}
