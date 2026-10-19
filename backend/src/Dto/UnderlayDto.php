<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\FloorUnderlay;

/**
 * The plan image under a floor's top view. Pixel (px, py), py counted down from the top, lies at plan
 * x = this.x + scale · (px · cos r + py · sin r), y = this.y + scale · (px · sin r − py · cos r), r = rotation.
 */
final readonly class UnderlayDto
{
	public function __construct(
		public int $fileId,
		public string $filename,
		public int $widthPx,
		public int $heightPx,
		public int $x,
		public int $y,
		public float $scale,
		public float $rotation,
		public float $opacity,
	) {
	}

	public static function fromEntity(FloorUnderlay $underlay): self
	{
		return new self(
			fileId: $underlay->file->id,
			filename: $underlay->file->filename,
			widthPx: $underlay->widthPx,
			heightPx: $underlay->heightPx,
			x: $underlay->x,
			y: $underlay->y,
			scale: $underlay->scale,
			rotation: $underlay->rotation,
			opacity: $underlay->opacity,
		);
	}
}
