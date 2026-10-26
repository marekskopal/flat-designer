<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** Which way an L or U stair turns at its landing, or a spiral winds, walking up. */
enum StairTurnEnum: string
{
	case Left = 'Left';
	case Right = 'Right';
}
