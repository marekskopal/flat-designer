<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use DateTimeImmutable;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Repository\ItemRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\ColumnEnum;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * Parametric object placed on a floor (kitchen cabinets, worktops, appliances…). The box is given by
 * its footprint centre (x, y), bottom elevation z, size (width × depth × height, mm) and rotation in
 * degrees counter-clockwise; at rotation 0 the front faces south (−y). Kind-specific options
 * (front layout, handles, appliances) live in the JSON `params` and are interpreted by the viewer. Items
 * that form one piece of furniture share a `group`.
 */
#[Entity(repositoryClass: ItemRepository::class)]
class Item extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	/** For a `Custom` item, the furniture definition it is drawn from (live: editing the definition changes the item). */
	#[ManyToOne(entityClass: FurnitureDefinition::class, nullable: true)]
	public ?FurnitureDefinition $definition = null;

	/** For a `Model` item, the 3D model it draws. */
	#[ManyToOne(entityClass: ModelAsset::class, nullable: true)]
	public ?ModelAsset $model = null;

	/** The piece of furniture the item is part of; a deleted group is not shown (undo brings it back). */
	#[ManyToOne(entityClass: ItemGroup::class, nullable: true)]
	public ?ItemGroup $group = null;

	/** `z` counts from the ground under the item's centre (its plot's terrain, FD-86) instead of the floor's level. */
	#[Column(type: Type::Boolean)]
	public bool $onGround = false;

	/**
	 * The item lies on the roof (FD-91): `z` counts from the top of the floor's roof covering under its centre, and the
	 * item takes the slope of the plane there (a solar panel, a vent tile, a roof step).
	 */
	#[Column(type: Type::Boolean)]
	public bool $onRoof = false;

	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
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
