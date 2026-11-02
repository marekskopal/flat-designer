<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/**
 * The infill of a railing: vertical balusters, horizontal rods or cables, a glass pane or a solid panel, between
 * the posts under the handrail; or a fence's: welded mesh panels or vertical boards on two rails, standing on
 * the ground with `follow`.
 */
enum RailingStyleEnum: string
{
	case Balusters = 'Balusters';
	case Rods = 'Rods';
	case Glass = 'Glass';
	case Panel = 'Panel';
	case Cable = 'Cable';
	case Mesh = 'Mesh';
	case Boards = 'Boards';

	/** mm, the clear gap between balusters, or the pitch of rods and cables, when none is given; null: no spacing. */
	public function defaultSpacing(): ?int
	{
		return match ($this) {
			self::Balusters => 100,
			self::Rods => 120,
			self::Cable => 100,
			self::Glass, self::Panel, self::Mesh, self::Boards => null,
		};
	}

	/** A fence's infill: drawn as the `Fence` item's, without a handrail. */
	public function isFence(): bool
	{
		return $this === self::Mesh || $this === self::Boards;
	}
}
