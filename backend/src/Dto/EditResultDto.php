<?php

declare(strict_types=1);

namespace FlatDesigner\Dto;

use JsonSerializable;
use stdClass;

/**
 * Answer to an edit, undo or redo: the project's new revision, the logged change and the element. An
 * operation on several elements (a group move, duplicate or delete, or its undo) also lists every change
 * of the batch with its element, in the order they were applied; `change`/`element` are then the first.
 */
final readonly class EditResultDto implements JsonSerializable
{
	/**
	 * @param array<string, mixed>|null $element state as in the scene; null when it was deleted
	 * @param list<array{change: ChangeDto, element: array<string, mixed>|null}>|null $batch
	 */
	public function __construct(public int $revision, public ?ChangeDto $change, public ?array $element, public ?array $batch = null)
	{
	}

	/**
	 * @return array{
	 *     revision: int,
	 *     change: ChangeDto|null,
	 *     element: array<string, mixed>|null,
	 *     batch?: list<array{change: ChangeDto, element: array<string, mixed>|null}>,
	 * }
	 */
	public function jsonSerialize(): array
	{
		$result = ['revision' => $this->revision, 'change' => $this->change, 'element' => self::element($this->element)];
		if ($this->batch !== null) {
			$result['batch'] = array_map(
				static fn (array $entry): array => ['change' => $entry['change'], 'element' => self::element($entry['element'])],
				$this->batch,
			);
		}

		return $result;
	}

	/**
	 * The state is decoded JSON, where an empty object became `[]`; item params are a JSON object (as in ItemDto).
	 *
	 * @param array<string, mixed>|null $element
	 * @return array<string, mixed>|null
	 */
	private static function element(?array $element): ?array
	{
		if ($element !== null && ($element['params'] ?? null) === []) {
			$element['params'] = new stdClass();
		}

		return $element;
	}
}
