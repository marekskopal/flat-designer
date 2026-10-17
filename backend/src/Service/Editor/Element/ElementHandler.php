<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;

/**
 * What the editor needs to know about one kind of floor element. The element's *state* is the JSON
 * object the scene serves for it (so the change log, undo and the viewer share one format); the editable
 * fields are a subset of it.
 */
interface ElementHandler
{
	public function type(): ElementTypeEnum;

	/** @return list<string> keys of the state a client may set */
	public function fields(): array;

	/**
	 * Keys of the state undo and redo write back: the editable fields plus any derived state the handler
	 * keeps consistent itself (e.g. a wall's opening offsets).
	 *
	 * @return list<string>
	 */
	public function restorableFields(): array;

	/**
	 * State of a new element before the client's fields are applied.
	 *
	 * @param array<string, mixed> $fields the client's fields (e.g. an item's kind picks its default size)
	 * @return array<string, mixed>
	 */
	public function defaults(array $fields): array;

	/** The element on the floor, deleted or not. */
	public function find(Floor $floor, int $id): ?AEntity;

	public function isDeleted(AEntity $element): bool;

	/** @return array<string, mixed> */
	public function state(AEntity $element): array;

	/**
	 * Checks the editable fields and returns them normalised (e.g. rotation into 0..359).
	 *
	 * @param array<string, mixed> $state
	 * @param AEntity|null $current the element being changed, null when creating
	 * @return array<string, mixed>
	 */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array;

	/** @param array<string, mixed> $state normalised */
	public function create(Floor $floor, array $state): AEntity;

	/** @param array<string, mixed> $state normalised */
	public function update(AEntity $element, array $state): void;

	public function setDeleted(AEntity $element, bool $deleted): void;

	/** @param array<string, mixed> $state */
	public function label(array $state): string;
}
