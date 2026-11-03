<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Mail queued during a request and sent after the client has its response (the worker calls `flush()` after
 * `frankenphp_finish_request()`), so a slow SMTP server neither delays the answer nor tells by its timing whether a
 * mail was sent (a password reset for an unknown address sends none). A failed send is logged, not retried.
 */
final class MailOutbox
{
	/** @var list<Email> */
	private array $queue = [];

	public function __construct(
		private readonly MailerInterface $mailer,
		private readonly MailConfig $config,
		private readonly LoggerInterface $logger,
	)
	{
	}

	public function queue(Email $email): void
	{
		$this->queue[] = $email->from($this->config->from);
	}

	public function reset(): void
	{
		$this->queue = [];
	}

	public function flush(): void
	{
		$queue = $this->queue;
		$this->queue = [];
		foreach ($queue as $email) {
			try {
				$this->mailer->send($email);
			} catch (TransportExceptionInterface $e) {
				$this->logger->error('Sending mail failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}
	}
}
