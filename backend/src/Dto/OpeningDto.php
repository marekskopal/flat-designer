<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Entity\Opening;

final readonly class OpeningDto
{
	public function __construct(
		public int $id,
		public string $name,
		public OpeningTypeEnum $type,
		public int $offset,
		public int $width,
		public int $height,
		public int $sillHeight,
		public ?int $leafWidth,
		public ?OpeningHingeEnum $hinge,
		public ?OpeningSideEnum $side,
		public bool $entrance,
	) {
	}

	public static function fromEntity(Opening $opening): self
	{
		return new self(
			id: $opening->id,
			name: $opening->name,
			type: $opening->type,
			offset: $opening->offset,
			width: $opening->width,
			height: $opening->height,
			sillHeight: $opening->sillHeight,
			leafWidth: $opening->leafWidth,
			hinge: $opening->hinge,
			side: $opening->side,
			entrance: $opening->entrance,
		);
	}
}
