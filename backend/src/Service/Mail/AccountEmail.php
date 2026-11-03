<?php

declare(strict_types=1);

namespace Izbik\Service\Mail;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use const ENT_QUOTES;

/** The layout of the mails about a user's account: a greeting, a paragraph, a button with a link and a footnote. */
final readonly class AccountEmail
{
	public static function create(
		string $recipientEmail,
		string $userName,
		string $subject,
		string $title,
		string $intro,
		string $buttonLabel,
		string $url,
		string $footnote,
	): Email {
		$escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
		$name = $escape($userName);
		$link = $escape($url);
		$heading = $escape($title);
		$paragraph = $escape($intro);
		$button = $escape($buttonLabel);
		$note = $escape($footnote);

		$font = 'font-family: Inter, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.5; color: #1f2a22;';
		$buttonStyle = 'display: inline-block; padding: 11px 22px; background-color: #4c5f51; color: #ffffff; text-decoration: none; '
			. 'border-radius: 8px; font-weight: 600;';
		$brandStyle = 'margin: 0 0 16px; font-size: 11px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #4c5f51;';
		$muted = 'color: #6b746d; font-size: 13px;';

		$html = <<<HTML
			<html>
			<body style="{$font} background-color: #f2f1ec; padding: 32px;">
				<div style="max-width: 520px; margin: 0 auto; padding: 28px; background-color: #ffffff; border-radius: 14px;">
					<p style="{$brandStyle}">Izbik</p>
					<h1 style="margin: 0 0 16px; font-size: 22px;">{$heading}</h1>
					<p>Hello {$name},</p>
					<p>{$paragraph}</p>
					<p style="text-align: center; margin: 28px 0;"><a href="{$link}" style="{$buttonStyle}">{$button}</a></p>
					<p style="{$muted}">If the button does not work, open this link:<br><a href="{$link}">{$link}</a></p>
					<p style="{$muted}">{$note}</p>
				</div>
			</body>
			</html>
			HTML;

		$text = sprintf("Hello %s,\n\n%s\n\n%s\n\n%s\n", $userName, $intro, $url, $footnote);

		return new Email()
			->to(new Address($recipientEmail, $userName))
			->subject($subject)
			->text($text)
			->html($html);
	}
}
