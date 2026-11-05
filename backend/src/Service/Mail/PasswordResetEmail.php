<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use Izbik\Model\Entity\User;
use Izbik\Service\Translator\Translator;
use Symfony\Component\Mime\Email;

/** The mail with a password reset link (`PasswordResetService`), in the account's language. */
final readonly class PasswordResetEmail
{
	public static function create(Translator $translator, User $user, string $resetUrl, int $lifetimeMinutes): Email
	{
		return AccountEmail::create(
			translator: $translator,
			locale: $user->locale,
			recipientEmail: $user->email,
			userName: $user->name,
			kind: 'passwordReset',
			url: $resetUrl,
			params: ['minutes' => $lifetimeMinutes],
		);
	}
}
