<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/**
 * The infill of a railing (FD-90): vertical balusters, horizontal rods or cables, a glass pane or a solid panel, between
 * the posts under the handrail.
 */
enum RailingStyleEnum: string
{
	case Balusters = 'Balusters';
	case Rods = 'Rods';
	case Glass = 'Glass';
	case Panel = 'Panel';
	case Cable = 'Cable';

	/** mm, the clear gap between balusters, or the pitch of rods and cables, when none is given; null: no spacing. */
	public function defaultSpacing(): ?int
	{
		return match ($this) {
			self::Balusters => 100,
			self::Rods => 120,
			self::Cable => 100,
			self::Glass, self::Panel => null,
		};
	}
}
