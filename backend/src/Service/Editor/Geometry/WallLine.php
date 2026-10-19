<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Geometry;

use FlatDesigner\Model\Entity\Wall;

/** The part of a wall that defines its footprint: the centre line and the thickness (mm). */
final readonly class WallLine
{
	public function __construct(
		public int $id,
		public int $startX,
		public int $startY,
		public int $endX,
		public int $endY,
		public int $thickness,
	) {
	}

	public static function fromWall(Wall $wall): self
	{
		return new self($wall->id, $wall->startX, $wall->startY, $wall->endX, $wall->endY, $wall->thickness);
	}

	public function length(): float
	{
		return hypot($this->endX - $this->startX, $this->endY - $this->startY);
	}

	/**
	 * Point at `along` mm on the centre line from the start and `across` mm to its left.
	 *
	 * @return array{float, float}
	 */
	public function point(float $along, float $across): array
	{
		$length = $this->length();
		$dx = ($this->endX - $this->startX) / $length;
		$dy = ($this->endY - $this->startY) / $length;

		return [$this->startX + $dx * $along - $dy * $across, $this->startY + $dy * $along + $dx * $across];
	}
}
