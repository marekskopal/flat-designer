<?php

declare(strict_types=1);

namespace FlatDesigner\Model\Entity\Enum;

enum ActorTypeEnum: string
{
	case Human = 'Human';
	case Agent = 'Agent';
}
