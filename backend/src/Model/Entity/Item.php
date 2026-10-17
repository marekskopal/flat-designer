<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Repository\ItemRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Parametric object placed in the flat (kitchen cabinets, worktops, appliances…). The box is given by
 * its footprint centre (x, y), bottom elevation z, size (width × depth × height, mm) and rotation in
 * degrees counter-clockwise; at rotation 0 the front faces south (−y). Kind-specific options
 * (front layout, handles, appliances) live in the JSON `params` and are interpreted by the viewer.
 */
#[Entity(repositoryClass: ItemRepository::class)]
class Item extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Flat::class)]
		public readonly Flat $flat,
		#[ManyToOne(entityClass: Room::class, nullable: true)]
		public ?Room $room,
		#[ColumnEnum(enum: ItemKindEnum::class)]
		public ItemKindEnum $kind,
		#[Column(type: Type::String)]
		public string $name,
		#[Column(type: Type::Int)]
		public int $x,
		#[Column(type: Type::Int)]
		public int $y,
		#[Column(type: Type::Int)]
		public int $z,
		#[Column(type: Type::Int)]
		public int $width,
		#[Column(type: Type::Int)]
		public int $depth,
		#[Column(type: Type::Int)]
		public int $height,
		#[Column(type: Type::Int)]
		public int $rotation,
		#[ManyToOne(entityClass: Material::class, nullable: true)]
		public ?Material $material,
		#[Column(type: Type::Text, nullable: true)]
		public ?string $params,
	) {
	}
}
