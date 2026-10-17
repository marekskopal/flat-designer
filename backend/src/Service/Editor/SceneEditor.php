<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use Closure;
use FlatDesigner\Dto\ChangeDto;
use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ChangeOperationEnum;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Service\Editor\Element\ElementHandler;
use FlatDesigner\Service\Editor\Element\State;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Project\ProjectRevisionService;
use MarekSkopal\ORM\ORM;

/**
 * Adds, changes and deletes floor elements for the app and MCP alike. Each edit runs in one
 * transaction: bump the project revision (with the optimistic check), validate and apply, log the change.
 * Group operations change several elements under one revision and log them as one `ChangeBatch`.
 * The caller has checked access to the floor's project.
 */
final readonly class SceneEditor
{
	/** mm along x by which a copy is shifted unless the caller places it. */
	private const int DuplicateShift = 100;

	public function __construct(
		private ElementHandlers $handlers,
		private ChangeRecorder $changeRecorder,
		private ProjectRevisionService $revisionService,
		private ItemRepository $itemRepository,
		private ORM $orm,
	) {
	}

	/** @param array<string, mixed> $fields any subset of the type's editable fields; the rest are defaults */
	public function create(User $user, Floor $floor, ElementTypeEnum $type, array $fields, ?int $baseRevision = null): EditResultDto
	{
		$handler = $this->handlers->get($type);

		return $this->transaction(function () use ($user, $floor, $handler, $fields, $baseRevision): EditResultDto {
			$revision = $this->revisionService->bump($floor->project, $baseRevision);
			$state = $handler->normalize($floor, self::editable($handler->fields(), $fields) + $handler->defaults($fields), null);
			$element = $handler->create($floor, $state);
			$after = $handler->state($element);
			$change = $this->changeRecorder->record(
				$user,
				$floor,
				$handler,
				ChangeOperationEnum::Create,
				$element->id,
				null,
				$after,
				$revision,
			);

			return new EditResultDto($revision, ChangeDto::fromEntity($change), $after);
		});
	}

	/**
	 * Changes the given fields only. Unknown fields are rejected; a change that alters nothing is not
	 * logged and does not bump the revision.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function update(
		User $user,
		Floor $floor,
		ElementTypeEnum $type,
		int $id,
		array $fields,
		?int $baseRevision = null,
	): EditResultDto
	{
		$handler = $this->handlers->get($type);

		return $this->transaction(function () use ($user, $floor, $handler, $id, $fields, $baseRevision): EditResultDto {
			$element = $handler->find($floor, $id);
			if ($element === null || $handler->isDeleted($element)) {
				throw EditException::notFound(sprintf('%s %d not found on this floor.', $handler->type()->value, $id));
			}

			$before = $handler->state($element);
			$state = $handler->normalize($floor, self::editable($handler->fields(), $fields) + $before, $element);
			if (State::equals(array_intersect_key($state, $before), array_intersect_key($before, $state))) {
				return new EditResultDto($floor->project->revision, null, $before);
			}

			$revision = $this->revisionService->bump($floor->project, $baseRevision);
			$handler->update($element, $state);
			$after = $handler->state($element);
			$change = $this->changeRecorder->record($user, $floor, $handler, ChangeOperationEnum::Update, $id, $before, $after, $revision);

			return new EditResultDto($revision, ChangeDto::fromEntity($change), $after);
		});
	}

	/** Deleting a group deletes its items too (`ungroup` keeps them). */
	public function delete(User $user, Floor $floor, ElementTypeEnum $type, int $id, ?int $baseRevision = null): EditResultDto
	{
		if ($type === ElementTypeEnum::Group) {
			return $this->deleteGroup($user, $floor, $id, $baseRevision);
		}

		$handler = $this->handlers->get($type);

		return $this->transaction(function () use ($user, $floor, $handler, $id, $baseRevision): EditResultDto {
			$element = $handler->find($floor, $id);
			if ($element === null || $handler->isDeleted($element)) {
				throw EditException::notFound(sprintf('%s %d not found on this floor.', $handler->type()->value, $id));
			}

			$revision = $this->revisionService->bump($floor->project, $baseRevision);
			$before = $handler->state($element);
			$handler->setDeleted($element, true);
			$change = $this->changeRecorder->record($user, $floor, $handler, ChangeOperationEnum::Delete, $id, $before, null, $revision);

			return new EditResultDto($revision, ChangeDto::fromEntity($change), null);
		});
	}

	/**
	 * Copy of an element; `$fields` override the copy's fields (e.g. its position). An item copy is
	 * shifted 100 mm along x, other copies stay in place (an opening copy needs another offset). A group
	 * is copied with its items (see `duplicateGroup`).
	 *
	 * @param array<string, mixed> $fields
	 */
	public function duplicate(
		User $user,
		Floor $floor,
		ElementTypeEnum $type,
		int $id,
		array $fields,
		?int $baseRevision = null,
	): EditResultDto
	{
		$handler = $this->handlers->get($type);
		if ($type === ElementTypeEnum::Group) {
			$name = self::editable($handler->fields(), $fields)['name'] ?? null;

			return $this->duplicateGroup($user, $floor, $id, self::DuplicateShift, 0, is_string($name) ? $name : null, $baseRevision);
		}

		$element = $handler->find($floor, $id);
		if ($element === null || $handler->isDeleted($element)) {
			throw EditException::notFound(sprintf('%s %d not found on this floor.', $type->value, $id));
		}

		$copy = array_intersect_key($handler->state($element), array_flip($handler->fields()));
		if ($type === ElementTypeEnum::Item && is_int($copy['x'] ?? null)) {
			$copy['x'] += self::DuplicateShift;
		}

		return $this->create($user, $floor, $type, self::editable($handler->fields(), $fields) + $copy, $baseRevision);
	}

	/**
	 * A new group of the given items (taken out of their previous groups); no items make an empty group.
	 *
	 * @param list<int> $itemIds
	 */
	public function createGroup(User $user, Floor $floor, string $name, array $itemIds, ?int $baseRevision = null): EditResultDto
	{
		$groupHandler = $this->handlers->get(ElementTypeEnum::Group);
		$itemHandler = $this->handlers->get(ElementTypeEnum::Item);

		return $this->transaction(
			function () use ($user, $floor, $name, $itemIds, $baseRevision, $groupHandler, $itemHandler): EditResultDto {
				$items = array_map(
					fn (int $id): AEntity => $this->existing($floor, $itemHandler, $id),
					array_values(array_unique($itemIds)),
				);
				$state = $groupHandler->normalize($floor, ['name' => $name], null);

				$batch = $this->startBatch(
					$floor,
					$baseRevision,
					sprintf('Grouped %d item(s) as %s', count($items), $groupHandler->label($state)),
				);
				$group = $this->batchCreate($user, $floor, $batch, $groupHandler, $state);
				foreach ($items as $item) {
					$this->batchUpdate($user, $floor, $batch, $itemHandler, $item, ['groupId' => $group->id]);
				}

				return $batch->result($groupHandler->state($group));
			},
		);
	}

	/**
	 * Moves the group's items by (dx, dy) mm and turns them by `rotation` degrees counter-clockwise about
	 * the centre of their joint footprint, as one change.
	 */
	public function moveGroup(
		User $user,
		Floor $floor,
		int $groupId,
		int $dx,
		int $dy,
		int $rotation = 0,
		?int $baseRevision = null,
	): EditResultDto {
		$groupHandler = $this->handlers->get(ElementTypeEnum::Group);
		$itemHandler = $this->handlers->get(ElementTypeEnum::Item);

		return $this->transaction(
			function () use ($user, $floor, $groupId, $dx, $dy, $rotation, $baseRevision, $groupHandler, $itemHandler): EditResultDto {
				$group = $this->group($floor, $groupId);
				$items = $this->itemRepository->findByGroup($group->id);
				$rotation %= 360;
				if ($items === [] || $dx === 0 && $dy === 0 && $rotation === 0) {
					return new EditResultDto($floor->project->revision, null, $groupHandler->state($group));
				}

				[$cx, $cy] = self::footprintCentre($items);
				$angle = deg2rad($rotation);
				$cos = cos($angle);
				$sin = sin($angle);

				$verb = $dx === 0 && $dy === 0 ? 'Rotated' : 'Moved';
				$batch = $this->startBatch($floor, $baseRevision, sprintf('%s group "%s"', $verb, $group->name));
				foreach ($items as $item) {
					$x = $item->x - $cx;
					$y = $item->y - $cy;
					$this->batchUpdate($user, $floor, $batch, $itemHandler, $item, [
						'x' => (int) round($cx + $x * $cos - $y * $sin) + $dx,
						'y' => (int) round($cy + $x * $sin + $y * $cos) + $dy,
						'rotation' => $item->rotation + $rotation,
					]);
				}

				return $batch->result($groupHandler->state($group));
			},
		);
	}

	/** Copy of the group with copies of its items, shifted by (dx, dy) mm. */
	public function duplicateGroup(
		User $user,
		Floor $floor,
		int $groupId,
		int $dx = self::DuplicateShift,
		int $dy = 0,
		?string $name = null,
		?int $baseRevision = null,
	): EditResultDto {
		$groupHandler = $this->handlers->get(ElementTypeEnum::Group);
		$itemHandler = $this->handlers->get(ElementTypeEnum::Item);

		return $this->transaction(
			function () use ($user, $floor, $groupId, $dx, $dy, $name, $baseRevision, $groupHandler, $itemHandler): EditResultDto {
				$source = $this->group($floor, $groupId);
				$state = $groupHandler->normalize($floor, ['name' => $name ?? $source->name], null);

				$batch = $this->startBatch($floor, $baseRevision, sprintf('Duplicated group "%s"', $source->name));
				$copy = $this->batchCreate($user, $floor, $batch, $groupHandler, $state);
				foreach ($this->itemRepository->findByGroup($source->id) as $item) {
					$fields = array_intersect_key($itemHandler->state($item), array_flip($itemHandler->fields()));
					$this->batchCreate($user, $floor, $batch, $itemHandler, $itemHandler->normalize($floor, [
						'groupId' => $copy->id,
						'x' => $item->x + $dx,
						'y' => $item->y + $dy,
					] + $fields, null));
				}

				return $batch->result($groupHandler->state($copy));
			},
		);
	}

	/** Dissolves the group; its items stay where they are. */
	public function ungroup(User $user, Floor $floor, int $groupId, ?int $baseRevision = null): EditResultDto
	{
		$groupHandler = $this->handlers->get(ElementTypeEnum::Group);
		$itemHandler = $this->handlers->get(ElementTypeEnum::Item);

		return $this->transaction(function () use ($user, $floor, $groupId, $baseRevision, $groupHandler, $itemHandler): EditResultDto {
			$group = $this->group($floor, $groupId);

			$batch = $this->startBatch($floor, $baseRevision, sprintf('Ungrouped "%s"', $group->name));
			foreach ($this->itemRepository->findByGroup($group->id) as $item) {
				$this->batchUpdate($user, $floor, $batch, $itemHandler, $item, ['groupId' => null]);
			}
			$this->batchDelete($user, $floor, $batch, $groupHandler, $group);

			return $batch->result(null);
		});
	}

	/** Deletes the group with its items. */
	public function deleteGroup(User $user, Floor $floor, int $groupId, ?int $baseRevision = null): EditResultDto
	{
		$groupHandler = $this->handlers->get(ElementTypeEnum::Group);
		$itemHandler = $this->handlers->get(ElementTypeEnum::Item);

		return $this->transaction(function () use ($user, $floor, $groupId, $baseRevision, $groupHandler, $itemHandler): EditResultDto {
			$group = $this->group($floor, $groupId);

			$batch = $this->startBatch($floor, $baseRevision, sprintf('Deleted group "%s"', $group->name));
			// Items first: undo goes backwards, so the group is back before its items need it.
			foreach ($this->itemRepository->findByGroup($group->id) as $item) {
				$this->batchDelete($user, $floor, $batch, $itemHandler, $item);
			}
			$this->batchDelete($user, $floor, $batch, $groupHandler, $group);

			return $batch->result(null);
		});
	}

	/**
	 * @param list<string> $editable
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private static function editable(array $editable, array $fields): array
	{
		$unknown = array_diff(array_keys($fields), $editable);
		if ($unknown !== []) {
			throw EditException::invalid(sprintf('Unknown field(s): %s. Editable: %s.', implode(', ', $unknown), implode(', ', $editable)));
		}

		return $fields;
	}

	private function startBatch(Floor $floor, ?int $baseRevision, string $summary): ChangeBatch
	{
		return new ChangeBatch($this->revisionService->bump($floor->project, $baseRevision), $summary);
	}

	/** @param array<string, mixed> $state normalised */
	private function batchCreate(User $user, Floor $floor, ChangeBatch $batch, ElementHandler $handler, array $state): AEntity
	{
		$element = $handler->create($floor, $state);
		$this->record($user, $floor, $batch, $handler, ChangeOperationEnum::Create, $element, null);

		return $element;
	}

	/** @param array<string, mixed> $fields */
	private function batchUpdate(
		User $user,
		Floor $floor,
		ChangeBatch $batch,
		ElementHandler $handler,
		AEntity $element,
		array $fields,
	): void
	{
		$before = $handler->state($element);
		$state = $handler->normalize($floor, $fields + $before, $element);
		if (State::equals(array_intersect_key($state, $before), array_intersect_key($before, $state))) {
			return;
		}

		$handler->update($element, $state);
		$this->record($user, $floor, $batch, $handler, ChangeOperationEnum::Update, $element, $before);
	}

	private function batchDelete(User $user, Floor $floor, ChangeBatch $batch, ElementHandler $handler, AEntity $element): void
	{
		$before = $handler->state($element);
		$handler->setDeleted($element, true);
		$this->record($user, $floor, $batch, $handler, ChangeOperationEnum::Delete, $element, $before);
	}

	/** @param array<string, mixed>|null $before */
	private function record(
		User $user,
		Floor $floor,
		ChangeBatch $batch,
		ElementHandler $handler,
		ChangeOperationEnum $operation,
		AEntity $element,
		?array $before,
	): void {
		$after = $operation === ChangeOperationEnum::Delete ? null : $handler->state($element);
		$change = $this->changeRecorder->record(
			$user,
			$floor,
			$handler,
			$operation,
			$element->id,
			$before,
			$after,
			$batch->revision,
			$batch->id,
			$batch->nextSummary(),
		);
		$batch->add($change, $after);
	}

	private function existing(Floor $floor, ElementHandler $handler, int $id): AEntity
	{
		$element = $handler->find($floor, $id);
		if ($element === null || $handler->isDeleted($element)) {
			throw EditException::notFound(sprintf('%s %d not found on this floor.', $handler->type()->value, $id));
		}

		return $element;
	}

	private function group(Floor $floor, int $id): ItemGroup
	{
		$group = $this->existing($floor, $this->handlers->get(ElementTypeEnum::Group), $id);
		assert($group instanceof ItemGroup);

		return $group;
	}

	/**
	 * Centre of the bounding box of the items' (rotated) footprints, in mm.
	 *
	 * @param non-empty-list<Item> $items
	 * @return array{float, float}
	 */
	private static function footprintCentre(array $items): array
	{
		$xs = [];
		$ys = [];
		foreach ($items as $item) {
			$angle = deg2rad($item->rotation);
			$halfX = (abs(cos($angle)) * $item->width + abs(sin($angle)) * $item->depth) / 2;
			$halfY = (abs(sin($angle)) * $item->width + abs(cos($angle)) * $item->depth) / 2;
			array_push($xs, $item->x - $halfX, $item->x + $halfX);
			array_push($ys, $item->y - $halfY, $item->y + $halfY);
		}

		return [(min($xs) + max($xs)) / 2, (min($ys) + max($ys)) / 2];
	}

	/** @param Closure(): EditResultDto $work */
	private function transaction(Closure $work): EditResultDto
	{
		return $this->orm->getTransactionProvider()->transaction($work);
	}
}
