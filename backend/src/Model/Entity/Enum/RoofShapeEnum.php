<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/**
 * A roof over a rectangle: two planes meeting at a ridge with gables at its ends, four planes rising from all the eaves
 * (a pyramid over a square), one plane rising from the front eaves to the back, or level.
 */
enum RoofShapeEnum: string
{
	case Gable = 'Gable';
	case Hip = 'Hip';
	case Shed = 'Shed';
	case Flat = 'Flat';

	/** Its planes rise at `pitch`; a flat roof ignores it. */
	public function isPitched(): bool
	{
		return $this !== self::Flat;
	}
}
