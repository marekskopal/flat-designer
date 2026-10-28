<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** How a roof window's sash opens, which only changes its look: about a pivot across its middle, or hung from its top. */
enum RoofWindowTypeEnum: string
{
	case CentrePivot = 'CentrePivot';
	case TopHung = 'TopHung';
}
