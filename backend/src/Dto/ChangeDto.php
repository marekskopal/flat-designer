<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use FlatDesigner\Model\Entity\Enum\ActorTypeEnum;
use FlatDesigner\Model\Entity\ProjectChange;
use const DATE_ATOM;

/** One entry of a project's activity list. */
final readonly class ChangeDto
{
	public function __construct(
		public int $id,
		public string $operation,
		public string $elementType,
		public int $elementId,
		public ?int $floorId,
		public string $summary,
		public string $actorType,
		/** The person, or for an agent the MCP client's name. */
		public string $actorName,
		public int $revision,
		public bool $undone,
		public string $createdAt,
	) {
	}

	public static function fromEntity(ProjectChange $change): self
	{
		return new self(
			id: $change->id,
			operation: $change->operation->value,
			elementType: $change->elementType->value,
			elementId: $change->elementId,
			floorId: $change->floor?->id,
			summary: $change->summary,
			actorType: $change->actorType->value,
			actorName: $change->actorType === ActorTypeEnum::Agent
				? $change->mcpClientName ?? 'Agent'
				: ($change->user->name ?? 'Deleted user'),
			revision: $change->revision,
			undone: $change->undoneAt !== null,
			createdAt: $change->createdAt->format(DATE_ATOM),
		);
	}
}
