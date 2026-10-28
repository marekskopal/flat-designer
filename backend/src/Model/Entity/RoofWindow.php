<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\RoofWindowTypeEnum;
use FlatDesigner\Model\Repository\RoofWindowRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A window in a roof's plane (FD-38). Its centre lies `along` the ridge and `across` it (mm, + towards the back) from
 * the roof's centre in plan, so it moves and turns with the roof; the plane under that point carries it, `width`
 * across the slope and `length` up it.
 */
#[Entity(repositoryClass: RoofWindowRepository::class)]
class RoofWindow extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	public function __construct(
		#[ManyToOne(entityClass: Roof::class)]
		public readonly Roof $roof,
		#[Column(type: Type::String)]
		public string $name,
		#[ColumnEnum(enum: RoofWindowTypeEnum::class)]
		public RoofWindowTypeEnum $type,
		#[Column(type: Type::Int)]
		public int $along,
		#[Column(type: Type::Int)]
		public int $across,
		#[Column(type: Type::Int)]
		public int $width,
		#[Column(type: Type::Int)]
		public int $length,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $frameMaterial = null,
	) {
	}
}
