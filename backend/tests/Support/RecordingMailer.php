<?php

declare(strict_types=1);

namespace Izbik\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** Stands in for the SMTP mailer in tests and keeps what was sent. */
final class RecordingMailer implements MailerInterface
{
	/** @var list<Email> */
	public array $sent = [];

	public function send(RawMessage $message, ?Envelope $envelope = null): void
	{
		assert($message instanceof Email);
		$this->sent[] = $message;
	}
}
