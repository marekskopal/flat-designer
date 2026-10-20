<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity;

use FlatDesigner\Model\Repository\FloorUnderlayRepository;
use MarekSkopal\ORM\Attribute\Column;
use MarekSkopal\ORM\Attribute\Entity;
use MarekSkopal\ORM\Attribute\ManyToOne;
use MarekSkopal\ORM\Enum\Type;

/**
 * A plan image (a project file) laid under the top view of a floor for tracing; at most one per floor. Pixel (px, py)
 * of the image, py counted down from the top, lies in the plan at (x, y) + R(rotation) · (px, −py) · scale, R turning
 * counter-clockwise. Not an element of the scene: it is set and removed as a whole, without undo.
 */
#[Entity(repositoryClass: FloorUnderlayRepository::class)]
class FloorUnderlay extends AEntity
{
	public function __construct(
		#[ManyToOne(entityClass: Floor::class)]
		public readonly Floor $floor,
		#[ManyToOne(entityClass: ProjectFile::class)]
		public ProjectFile $file,
		#[Column(type: Type::Int)]
		public int $widthPx,
		#[Column(type: Type::Int)]
		public int $heightPx,
		/** Plan position of the image's top-left corner, mm. */
		#[Column(type: Type::Int)]
		public int $x,
		#[Column(type: Type::Int)]
		public int $y,
		/** mm per image pixel. */
		#[Column(type: Type::Double)]
		public float $scale,
		/** Degrees counter-clockwise, 0 ≤ rotation < 360. */
		#[Column(type: Type::Double)]
		public float $rotation,
		#[Column(type: Type::Double)]
		public float $opacity,
	) {
	}
}
