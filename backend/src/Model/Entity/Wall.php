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

/**
 * Wall given by its centre line (start → end, mm) and thickness. The centre line is straight, or with a `sagitta` (mm
 * from the chord's midpoint to the arc, + to the left of start → end) an arc of at most a semicircle. `leftFinishes` / `rightFinishes` are optional
 * JSON lists of wall finish bands on the face left / right of start → end, overriding the bands of the room the face
 * borders (`Room::$wallFinishes`); `[]` means no bands on that face, null the room's.
 */
#[Entity(repositoryClass: WallRepository::class)]
class Wall extends AEntity
{
	/** Set when deleted in the editor; the row stays so undo can bring it back with the same id. */
	#[Column(type: Type::Timestamp, nullable: true)]
	public ?DateTimeImmutable $deletedAt = null;

	#[Column(type: Type::Int)]
	public int $sagitta = 0;

	#[Column(type: Type::Text, nullable: true)]
	public ?string $leftFinishes = null;

	#[Column(type: Type::Text, nullable: true)]
	public ?string $rightFinishes = null;

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
