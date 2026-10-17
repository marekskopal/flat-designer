<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Authentication;

final readonly class TokenPayload
{
	public function __construct(public int $userId, public int $tokenVersion)
	{
	}
}
