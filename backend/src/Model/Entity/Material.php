<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Entity\Enum\MaterialPatternEnum;
use FlatDesigner\Model\Repository\MaterialRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Enum\Type;

/**
 * PBR surface description. `pattern` selects how the viewer lays the surface out (planks, tiles, wood
 * grain…). `texture` is an optional image of one plank or tile (path under the frontend's assets/); the
 * viewer composes the floor or wall from it at `tileWidth` × `tileHeight` mm. Without a texture the
 * pattern is drawn procedurally from `color`.
 */
#[Entity(repositoryClass: MaterialRepository::class)]
class Material extends AEntity
{
	public function __construct(
		#[Column(type: Type::String, size: 64)]
		public string $code,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: MaterialPatternEnum::class)]
		public MaterialPatternEnum $pattern,
		#[Column(type: Type::String, size: 7)]
		public string $color,
		#[Column(type: Type::Float)]
		public float $roughness,
		#[Column(type: Type::Float)]
		public float $metalness,
		#[Column(type: Type::String, nullable: true)]
		public ?string $texture,
		#[Column(type: Type::Int, nullable: true)]
		public ?int $tileWidth,
		#[Column(type: Type::Int, nullable: true)]
		public ?int $tileHeight,
	) {
	}
}
