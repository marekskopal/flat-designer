<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use Symfony\Component\Mime\Email;

/** The mail with a password reset link (`PasswordResetService`). */
final readonly class PasswordResetEmail
{
	public static function create(string $recipientEmail, string $userName, string $resetUrl, int $lifetimeMinutes): Email
	{
		return AccountEmail::create(
			recipientEmail: $recipientEmail,
			userName: $userName,
			subject: 'Reset your Izbik password',
			title: 'Reset your password',
			intro: 'Someone asked to reset the password of your Izbik account. Choose a new one with the link below.',
			buttonLabel: 'Choose a new password',
			url: $resetUrl,
			footnote: sprintf(
				'The link works once, for %d minutes. If you did not ask for it, ignore this mail; your password stays as it is.',
				$lifetimeMinutes,
			),
		);
	}
}
