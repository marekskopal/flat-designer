<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use SensitiveParameter;

/**
 * Outgoing mail over SMTP (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `EMAIL_FROM`). Without `SMTP_HOST` no
 * mail is sent, and the features needing it (password reset) are off.
 */
final readonly class MailConfig
{
	private const string DefaultFrom = 'no-reply@izbik.local';
	private const string DefaultPort = '25';

	public function __construct(
		public string $host,
		public string $port,
		public string $user,
		#[SensitiveParameter] public string $password,
		public string $from,
	) {
	}

	public static function fromEnv(): self
	{
		$port = (string) getenv('SMTP_PORT');
		$from = (string) getenv('EMAIL_FROM');

		return new self(
			host: (string) getenv('SMTP_HOST'),
			port: $port !== '' ? $port : self::DefaultPort,
			user: (string) getenv('SMTP_USER'),
			password: (string) getenv('SMTP_PASSWORD'),
			from: $from !== '' ? $from : self::DefaultFrom,
		);
	}

	public function isEnabled(): bool
	{
		return $this->host !== '';
	}

	/** The Symfony Mailer DSN; `null://null` (sending nothing) when mail is off. */
	public function dsn(): string
	{
		if (!$this->isEnabled()) {
			return 'null://null';
		}
		$credentials = $this->user !== '' ? rawurlencode($this->user) . ':' . rawurlencode($this->password) . '@' : '';

		return 'smtp://' . $credentials . $this->host . ':' . $this->port;
	}
}
