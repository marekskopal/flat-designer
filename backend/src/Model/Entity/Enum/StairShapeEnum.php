<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** A stair's plan: one flight, two flights round a quarter landing (L) or a half landing (U), or a spiral. */
enum StairShapeEnum: string
{
	case Straight = 'Straight';
	case LShaped = 'LShaped';
	case UShaped = 'UShaped';
	case Spiral = 'Spiral';

	/** Two flights with a landing between them, split at `firstFlight`. */
	public function hasLanding(): bool
	{
		return $this === self::LShaped || $this === self::UShaped;
	}
}
