<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

/** The caps along a pitched roof's ridge and hips (FD-91): a square cap, half-round ridge tiles, or an angled metal ridge. */
enum RidgeStyleEnum: string
{
	case Box = 'Box';
	case Round = 'Round';
	case Angled = 'Angled';
}
