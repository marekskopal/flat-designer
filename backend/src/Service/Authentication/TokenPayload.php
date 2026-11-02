<?php

declare(strict_types=1);

namespace Izbik\Service\Authentication;

final readonly class TokenPayload
{
	public function __construct(public int $userId, public int $tokenVersion)
	{
	}
}
