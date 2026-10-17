<?php

declare(strict_types=1);

namespace FlatDesigner\Mcp\Tool;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Service\Editor\UndoService;
use Mcp\Capability\Attribute\McpTool;

/** Undo/redo of this MCP client's own changes. */
final readonly class HistoryTools
{
	public function __construct(private ToolContext $context, private UndoService $undoService)
	{
	}

	#[McpTool(
		name: 'undo',
		description: 'Undo your most recent change in the project (not the user\'s). Fails if someone changed that element since.',
	)]
	public function undo(int $projectId): EditResultDto
	{
		return $this->undoService->undo($this->context->user(), $this->context->project($projectId));
	}

	#[McpTool(name: 'redo', description: 'Redo your most recently undone change.')]
	public function redo(int $projectId): EditResultDto
	{
		return $this->undoService->redo($this->context->user(), $this->context->project($projectId));
	}
}
