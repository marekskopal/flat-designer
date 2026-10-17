<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Realtime;

/**
 * The browser tab a request came from (`X-Origin-Client-Id`), echoed in live updates so that tab can
 * ignore the update caused by its own edit. Reset by the worker per request.
 */
final class RealtimeOriginContext
{
	public const string Header = 'X-Origin-Client-Id';

	private const int MaxLength = 64;

	private ?string $clientId = null;

	public function set(?string $clientId): void
	{
		$this->clientId = $clientId !== null && $clientId !== '' && strlen($clientId) <= self::MaxLength && ctype_print($clientId)
			? $clientId
			: null;
	}

	public function get(): ?string
	{
		return $this->clientId;
	}
}
