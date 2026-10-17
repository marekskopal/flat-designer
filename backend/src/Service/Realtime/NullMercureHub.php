<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Realtime;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

/** Used when Mercure is not configured (CLI, misconfigured env): publishing does nothing. */
final readonly class NullMercureHub implements HubInterface
{
	public function publish(Update $update): string
	{
		return '';
	}

	public function getPublicUrl(): string
	{
		return '';
	}

	public function getFactory(): ?TokenFactoryInterface
	{
		return null;
	}

	public function getProtocolVersion(): ProtocolVersion
	{
		return ProtocolVersion::Legacy;
	}

	public function getCookieName(): string
	{
		return ProtocolVersion::Legacy->getDefaultCookieName();
	}
}
