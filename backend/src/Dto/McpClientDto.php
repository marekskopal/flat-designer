<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** An MCP client the user has authorized. */
final readonly class McpClientDto
{
	public function __construct(
		public string $clientId,
		public string $clientName,
		public string $connectedAt,
		public string $lastTokenAt,
		/** Holds a usable access or refresh token. */
		public bool $active,
	) {
	}
}
