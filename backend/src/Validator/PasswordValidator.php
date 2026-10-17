<?php

declare(strict_types=1);

namespace FlatDesigner\Validator;

use SensitiveParameter;

final readonly class PasswordValidator
{
	public const string Requirements = 'Password must be at least 8 characters and contain uppercase, lowercase, and a digit.';

	public static function isValid(#[SensitiveParameter] string $password): bool
	{
		return strlen($password) >= 8
			&& (bool) preg_match('/[A-Z]/', $password)
			&& (bool) preg_match('/[a-z]/', $password)
			&& (bool) preg_match('/[0-9]/', $password);
	}
}
