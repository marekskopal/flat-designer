<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp;

use FlatDesigner\Model\Entity\User;
use RuntimeException;

/**
 * The user an MCP request acts for, resolved from its OAuth access token, and the app's base URL the request came
 * through (for links the tools hand out). Reset by the worker per request.
 */
final class McpUserContext
{
	private ?User $user = null;

	private string $baseUrl = '';

	public function setUser(User $user, string $baseUrl = ''): void
	{
		$this->user = $user;
		$this->baseUrl = $baseUrl;
	}

	public function getBaseUrl(): string
	{
		return $this->baseUrl;
	}

	public function getUser(): User
	{
		return $this->user ?? throw new RuntimeException('MCP user context has not been initialized.');
	}

	public function clear(): void
	{
		$this->user = null;
		$this->baseUrl = '';
	}
}
