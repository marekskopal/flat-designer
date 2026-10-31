<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Roof;
use FlatDesigner\Service\Json\JsonDecoder;

/**
 * A roof as the scene serves it, with its windows (elements of their own, as a wall's openings are), and its rainwater
 * goods, snow guards and ridge (FD-91).
 */
final readonly class RoofDto
{
	/**
	 * @param list<RoofWindowDto> $windows
	 * @param list<array{edge: string, along: int}>|null $downpipes null: at the eaves' corners
	 */
	public function __construct(
		public int $id,
		public string $name,
		public string $shape,
		public int $x,
		public int $y,
		public int $rotation,
		public int $width,
		public int $depth,
		public int $eaveHeight,
		public int $pitch,
		public int $overhang,
		public int $vergeOverhang,
		public int $thickness,
		public ?int $parapet,
		public ?int $coveringMaterialId,
		public ?int $soffitMaterialId,
		public array $windows,
		public string $gutters = 'None',
		public ?int $gutterMaterialId = null,
		public ?array $downpipes = null,
		public int $snowGuards = 0,
		public string $ridgeStyle = 'Box',
	) {
	}

	/** @param list<RoofWindowDto> $windows */
	public static function fromEntity(Roof $roof, array $windows): self
	{
		return new self(
			id: $roof->id,
			name: $roof->name,
			shape: $roof->shape->value,
			x: $roof->x,
			y: $roof->y,
			rotation: $roof->rotation,
			width: $roof->width,
			depth: $roof->depth,
			eaveHeight: $roof->eaveHeight,
			pitch: $roof->pitch,
			overhang: $roof->overhang,
			vergeOverhang: $roof->vergeOverhang,
			thickness: $roof->thickness,
			parapet: $roof->parapet,
			coveringMaterialId: $roof->coveringMaterial?->id,
			soffitMaterialId: $roof->soffitMaterial?->id,
			windows: $windows,
			gutters: $roof->gutters->value,
			gutterMaterialId: $roof->gutterMaterial?->id,
			downpipes: $roof->downpipes === null ? null : JsonDecoder::decodeDownpipes($roof->downpipes),
			snowGuards: $roof->snowGuards,
			ridgeStyle: $roof->ridgeStyle->value,
		);
	}
}
