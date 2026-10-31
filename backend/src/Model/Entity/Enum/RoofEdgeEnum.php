<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/**
 * An eaves edge of a roof in its own frame (FD-91): the front (−t, where a shed rises from), the back, and a hip roof's
 * left (−s) and right sides.
 */
enum RoofEdgeEnum: string
{
	case Front = 'Front';
	case Back = 'Back';
	case Left = 'Left';
	case Right = 'Right';

	/** Whether a roof of the shape has eaves (and so a gutter) along this edge. */
	public function isEavesOf(RoofShapeEnum $shape): bool
	{
		return match ($shape) {
			RoofShapeEnum::Gable => $this === self::Front || $this === self::Back,
			RoofShapeEnum::Hip => true,
			RoofShapeEnum::Shed => $this === self::Front,
			RoofShapeEnum::Flat => false,
		};
	}

	/** Whether the edge runs along the roof's width (the front and back) rather than its depth. */
	public function alongWidth(): bool
	{
		return $this === self::Front || $this === self::Back;
	}
}
