<?php

declare(strict_types=1);

namespace FlatDesigner\Validator;

use const FILTER_VALIDATE_EMAIL;

final readonly class UserFieldValidator
{
	private const int MaxLength = 255;

	/** Trimmed, lower-cased address, or null when it is not a valid email. */
	public static function normalizeEmail(string $email): ?string
	{
		$email = mb_strtolower(trim($email));
		if ($email === '' || mb_strlen($email) > self::MaxLength || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return null;
		}

		return $email;
	}

	/** Trimmed display name, or null when it is empty or too long. */
	public static function normalizeName(string $name): ?string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MaxLength) {
			return null;
		}

		return $name;
	}
}
