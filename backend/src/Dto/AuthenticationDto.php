<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

final readonly class AuthenticationDto
{
	public function __construct(public string $accessToken, public string $refreshToken, public int $userId)
	{
	}
}
