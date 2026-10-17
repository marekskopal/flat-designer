<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\WallTypeEnum;
use FlatDesigner\Model\Repository\WallRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/** Straight wall given by its centre line (start → end, mm) and thickness. */
#[Entity(repositoryClass: WallRepository::class)]
class Wall extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: WallTypeEnum::class)]
		public WallTypeEnum $type,
		#[Column(type: Type::Int)]
		public int $startX,
		#[Column(type: Type::Int)]
		public int $startY,
		#[Column(type: Type::Int)]
		public int $endX,
		#[Column(type: Type::Int)]
		public int $endY,
		#[Column(type: Type::Int)]
		public int $thickness,
		#[Column(type: Type::Int)]
		public int $height,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $material,
	) {
	}
}
