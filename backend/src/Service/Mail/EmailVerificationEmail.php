<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use Symfony\Component\Mime\Email;

/** The mail with a link confirming the account's address (`EmailVerificationService`). */
final readonly class EmailVerificationEmail
{
	public static function create(string $recipientEmail, string $userName, string $verifyUrl, int $lifetimeHours): Email
	{
		return AccountEmail::create(
			recipientEmail: $recipientEmail,
			userName: $userName,
			subject: 'Confirm your email for Izbik',
			title: 'Confirm your email',
			intro: 'Please confirm that this is the address of your Izbik account with the link below.',
			buttonLabel: 'Confirm email',
			url: $verifyUrl,
			footnote: sprintf(
				'The link works once, for %d hours. If you did not create an Izbik account, ignore this mail.',
				$lifetimeHours,
			),
		);
	}
}
