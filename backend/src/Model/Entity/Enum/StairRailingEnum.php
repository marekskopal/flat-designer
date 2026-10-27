<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** The stair's railing on its left or right side walking up, both or none; it also runs round the void upstairs. */
enum StairRailingEnum: string
{
	case None = 'None';
	case Left = 'Left';
	case Right = 'Right';
	case Both = 'Both';
}
