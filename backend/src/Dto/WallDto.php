<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Entity\Wall;

final readonly class WallDto
{
	/** @param list<OpeningDto> $openings */
	public function __construct(
		public int $id,
		public string $name,
		public WallTypeEnum $type,
		public int $startX,
		public int $startY,
		public int $endX,
		public int $endY,
		public int $thickness,
		public int $height,
		public ?int $materialId,
		public array $openings,
	) {
	}

	/** @param list<OpeningDto> $openings */
	public static function fromEntity(Wall $wall, array $openings): self
	{
		return new self(
			id: $wall->id,
			name: $wall->name,
			type: $wall->type,
			startX: $wall->startX,
			startY: $wall->startY,
			endX: $wall->endX,
			endY: $wall->endY,
			thickness: $wall->thickness,
			height: $wall->height,
			materialId: $wall->material?->id,
			openings: $openings,
		);
	}
}
