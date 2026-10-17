<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp;

use FlatDesigner\Model\Entity\User;
use RuntimeException;

/** The user an MCP request acts for, resolved from its OAuth access token. Reset by the worker per request. */
final class McpUserContext
{
	private ?User $user = null;

	public function setUser(User $user): void
	{
		$this->user = $user;
	}

	public function getUser(): User
	{
		return $this->user ?? throw new RuntimeException('MCP user context has not been initialized.');
	}

	public function clear(): void
	{
		$this->user = null;
	}
}
