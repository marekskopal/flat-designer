<?php

declare(strict_types=1);

namespace Izbik\Service\Editor\Drawing;

use Izbik\Service\Editor\Exception\EditException;
use Izbik\Service\Editor\Geometry\WallLine;

/** One face of a straight wall a cabinet run stands against (`CabinetRun`): `sign` 1 its left face, −1 its right. */
final readonly class RunFace
{
	public function __construct(private WallLine $line, private int $sign, private float $halfThickness)
	{
		if ($line->chord() < 1) {
			throw EditException::invalid('A cabinet run needs a wall of some length.');
		}
	}

	public function length(): int
	{
		return (int) floor($this->line->length());
	}

	/**
	 * The fields with the item placed: its back on the face, `along` mm from the wall start to its start, its front
	 * (local −y at rotation 0, turned counter-clockwise) facing away from the wall.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	public function place(array $fields, int $along, int $width, int $depth): array
	{
		[$x, $y] = $this->line->point($along + $width / 2, $this->sign * ($this->halfThickness + $depth / 2));
		[$dx, $dy] = $this->line->tangent($along);
		// The face's normal out into the room: the left normal of the wall direction, or its opposite.
		[$nx, $ny] = [-$dy * $this->sign, $dx * $this->sign];
		$rotation = (int) round(rad2deg(atan2($nx, -$ny)));

		return ['x' => (int) round($x), 'y' => (int) round($y), 'rotation' => ($rotation % 360 + 360) % 360] + $fields;
	}
}
