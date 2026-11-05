<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use Izbik\Model\Entity\User;
use Izbik\Service\Translator\Translator;
use Symfony\Component\Mime\Email;

/** The mail with a link confirming the account's address (`EmailVerificationService`), in the account's language. */
final readonly class EmailVerificationEmail
{
	public static function create(Translator $translator, User $user, string $verifyUrl, int $lifetimeHours): Email
	{
		return AccountEmail::create(
			translator: $translator,
			locale: $user->locale,
			recipientEmail: $user->email,
			userName: $user->name,
			kind: 'emailVerification',
			url: $verifyUrl,
			params: ['hours' => $lifetimeHours],
		);
	}
}
