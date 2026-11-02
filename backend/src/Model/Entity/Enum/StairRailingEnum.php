<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/** A side of a stair walking up, both or none: where it has a railing, or a handrail on the wall. */
enum StairRailingEnum: string
{
	case None = 'None';
	case Left = 'Left';
	case Right = 'Right';
	case Both = 'Both';
}
