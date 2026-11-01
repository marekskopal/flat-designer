<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/**
 * How a railing (a fence, FD-100) meets the ground: `Level` at its `baseZ`; `Rake` standing on the floor's plots' ground,
 * its panels running with it; `Step` with each bay level over the highest ground under it.
 */
enum RailingFollowEnum: string
{
	case Level = 'Level';
	case Rake = 'Rake';
	case Step = 'Step';
}
