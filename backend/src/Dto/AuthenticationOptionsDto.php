<?php

declare(strict_types=1);

namespace Izbik\Dto;

/**
 * What the sign-in page offers besides email and password: Sign in with Google (its web client id, null when off), and
 * whether the server sends mail (password reset and email verification links).
 */
final readonly class AuthenticationOptionsDto
{
	public function __construct(public ?string $googleClientId, public bool $mail)
	{
	}
}
