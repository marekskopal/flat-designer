<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

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

	/** Its ends along the ridge are verges (gables, a shed's sides), which may be skewed. */
	public function hasVerges(): bool
	{
		return $this === self::Gable || $this === self::Shed;
	}

	/** Whether its ends may be skewed: a gable's or shed's verges, a flat roof's ends over a skewed wall. */
	public function canSkew(): bool
	{
		return $this->hasVerges() || $this === self::Flat;
	}
}
