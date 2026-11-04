<?php

declare(strict_types=1);

namespace Izbik\Service\Authentication;

/**
 * Whether anyone may create an account (`SIGN_UP_ENABLED`: 1, true, yes or on; off when unset). Off, only an admin adds
 * accounts (the Users page, `make admin`), and Sign in with Google signs in existing accounts only, so an instance on the
 * internet is not open to everyone. Not readonly: the tests close it for a case (`AppHarness::signUp()`).
 */
final class SignUpConfig
{
	public function __construct(public bool $enabled)
	{
	}

	public static function fromEnv(): self
	{
		return new self(in_array(strtolower(trim((string) getenv('SIGN_UP_ENABLED'))), ['1', 'true', 'yes', 'on'], true));
	}
}
