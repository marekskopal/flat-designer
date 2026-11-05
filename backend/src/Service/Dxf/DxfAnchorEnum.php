<?php

declare(strict_types=1);

namespace Izbik\Service\Dxf;

/** Where the drawing lands on the plan before the offset: its own origin at the plan's, or the walls' south-west corner. */
enum DxfAnchorEnum: string
{
	case Drawing = 'Drawing';
	case Walls = 'Walls';
}
