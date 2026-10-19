<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/**
 * Face of a door leaf: `Flush` a plain slab, `Panelled` raised panels stacked in a frame of stiles and rails,
 * `Glazed` glass panes in such a frame. The number of panels or panes is the opening's `leafPanels`.
 */
enum DoorLeafStyleEnum: string
{
	case Flush = 'Flush';
	case Panelled = 'Panelled';
	case Glazed = 'Glazed';
}
