<?php

declare(strict_types=1);

namespace Izbik\Service\Authentication;

/** The person a verified Google ID token names: its `sub` (stable account id), verified email and display name. */
final readonly class GoogleIdentity
{
	public function __construct(public string $subject, public string $email, public string $name)
	{
	}
}
