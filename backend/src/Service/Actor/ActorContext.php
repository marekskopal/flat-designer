<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Actor;

use FlatDesigner\Model\Entity\Enum\ActorTypeEnum;

/**
 * Who is making the current request's changes: a person in the app (default) or an agent connected
 * over MCP, named by its OAuth client. Shared per worker, so the worker resets it before each request.
 */
final class ActorContext
{
	private ?string $mcpClientId = null;

	private ?string $mcpClientName = null;

	public function setHuman(): void
	{
		$this->mcpClientId = null;
		$this->mcpClientName = null;
	}

	public function setAgent(string $mcpClientId, string $mcpClientName): void
	{
		$this->mcpClientId = $mcpClientId;
		$this->mcpClientName = $mcpClientName;
	}

	public function getActorType(): ActorTypeEnum
	{
		return $this->mcpClientId === null ? ActorTypeEnum::Human : ActorTypeEnum::Agent;
	}

	public function getMcpClientId(): ?string
	{
		return $this->mcpClientId;
	}

	public function getMcpClientName(): ?string
	{
		return $this->mcpClientName;
	}
}
