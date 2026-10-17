<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use JsonSerializable;
use stdClass;

/** Answer to an edit, undo or redo: the project's new revision, the logged change and the element. */
final readonly class EditResultDto implements JsonSerializable
{
	/** @param array<string, mixed>|null $element state as in the scene; null when it was deleted */
	public function __construct(public int $revision, public ?ChangeDto $change, public ?array $element)
	{
	}

	/** @return array{revision: int, change: ChangeDto|null, element: array<string, mixed>|null} */
	public function jsonSerialize(): array
	{
		$element = $this->element;
		// The state is decoded JSON, where an empty object became `[]`; item params are a JSON object (as in ItemDto).
		if ($element !== null && ($element['params'] ?? null) === []) {
			$element['params'] = new stdClass();
		}

		return ['revision' => $this->revision, 'change' => $this->change, 'element' => $element];
	}
}
