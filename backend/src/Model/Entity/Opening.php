<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Entity\Enum\OpeningHingeEnum;
use FlatDesigner\Model\Entity\Enum\OpeningSideEnum;
use FlatDesigner\Model\Entity\Enum\OpeningTypeEnum;
use FlatDesigner\Model\Repository\OpeningRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Door or window cut into a wall. `offset` is the distance (mm) from the wall start to the near edge
 * of the opening, measured along the centre line. For doors, `hinge` says which edge (nearer the
 * wall start or end) carries the leaf and `side` which side of the wall (left/right when walking
 * from start to end) it swings into; `leafWidth` is the nominal door leaf width.
 */
#[Entity(repositoryClass: OpeningRepository::class)]
class Opening extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Wall::class)]
		public readonly Wall $wall,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: OpeningTypeEnum::class)]
		public OpeningTypeEnum $type,
		#[Column(type: Type::Int)]
		public int $offset,
		#[Column(type: Type::Int)]
		public int $width,
		#[Column(type: Type::Int)]
		public int $height,
		#[Column(type: Type::Int)]
		public int $sillHeight,
		#[Column(type: Type::Int, nullable: true)]
		public ?int $leafWidth,
		#[ColumnEnum(enum: OpeningHingeEnum::class, nullable: true)]
		public ?OpeningHingeEnum $hinge,
		#[ColumnEnum(enum: OpeningSideEnum::class, nullable: true)]
		public ?OpeningSideEnum $side,
	) {
	}
}
