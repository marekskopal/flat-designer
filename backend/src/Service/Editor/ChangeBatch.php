<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Dto\ChangeDto;
use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\ProjectChange;

/**
 * The changes one editor operation makes to several elements, under one revision. They share the `batch`
 * id, so `UndoService` reverts them together; the first change carries the operation's summary
 * (e.g. "Moved group "Hall stand"") and stands for the batch in the activity list.
 */
final class ChangeBatch
{
	public readonly string $id;

	/** @var list<array{change: ProjectChange, element: array<string, mixed>|null}> */
	private array $entries = [];

	public function __construct(public readonly int $revision, private readonly string $summary)
	{
		$this->id = bin2hex(random_bytes(8));
	}

	/** The summary for the next change: the operation's for the first, the derived one for the rest. */
	public function nextSummary(): ?string
	{
		return $this->entries === [] ? $this->summary : null;
	}

	/** @param array<string, mixed>|null $element */
	public function add(ProjectChange $change, ?array $element): void
	{
		$this->entries[] = ['change' => $change, 'element' => $element];
	}

	/** @param array<string, mixed>|null $element the element the operation is about (e.g. the group), as `element` */
	public function result(?array $element): EditResultDto
	{
		$batch = array_map(
			static fn (array $entry): array => ['change' => ChangeDto::fromEntity($entry['change']), 'element' => $entry['element']],
			$this->entries,
		);

		return new EditResultDto($this->revision, $batch[0]['change'] ?? null, $element, $batch);
	}
}
