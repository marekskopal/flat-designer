<?php

declare(strict_types=1);

namespace Izbik\Model\Entity\Enum;

/** The caps along a pitched roof's ridge and hips: a square cap, half-round ridge tiles, or an angled metal ridge. */
enum RidgeStyleEnum: string
{
	case Box = 'Box';
	case Round = 'Round';
	case Angled = 'Angled';
}
