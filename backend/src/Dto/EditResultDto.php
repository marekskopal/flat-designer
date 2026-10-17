<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

/** Answer to an edit, undo or redo: the project's new revision, the logged change and the element. */
final readonly class EditResultDto
{
	/** @param array<string, mixed>|null $element state as in the scene; null when it was deleted */
	public function __construct(public int $revision, public ?ChangeDto $change, public ?array $element)
	{
	}
}
