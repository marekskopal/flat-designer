<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use DateTimeImmutable;
use FlatDesigner\Service\Upload\UploadTicketService;
use RuntimeException;
use const DATE_ATOM;

/** The two ways the upload tools take a file: base64 content in the call, or a one-time upload URL. */
final class UploadContent
{
	public static function decodeBase64(string $content): string
	{
		// Agents often send a data URI (data:image/png;base64,…) or wrapped lines.
		$bytes = base64_decode(preg_replace('/^data:[^,]*,|\s+/', '', $content) ?? '', true);
		if ($bytes === false) {
			throw new RuntimeException('"content" is not valid base64.');
		}

		return $bytes;
	}

	/** @return array{uploadUrl: string, expiresAt: string, curl: string} the URL and the curl command that sends a file to it */
	public static function uploadUrl(string $baseUrl, string $token, DateTimeImmutable $expiresAt): array
	{
		$url = $baseUrl . UploadTicketService::path($token);

		return [
			'uploadUrl' => $url,
			'expiresAt' => $expiresAt->format(DATE_ATOM),
			'curl' => sprintf("curl -sS --fail-with-body -T '<local file>' '%s'", $url),
		];
	}
}
