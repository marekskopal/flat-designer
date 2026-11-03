<?php

declare(strict_types=1);

namespace Izbik\Service\Authentication;

use SensitiveParameter;

/** Sign in with Google: the web client id the frontend renders the button with, and the check of the ID token it returns. */
interface GoogleAuthServiceInterface
{
	/** The OAuth web client id (`GOOGLE_CLIENT_ID`), or null when Sign in with Google is off. */
	public function clientId(): ?string;

	public function verifyIdToken(#[SensitiveParameter] string $idToken): GoogleIdentity;
}
