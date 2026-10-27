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
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Editor\Element\State;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Project\ProjectRevisionService;
use MarekSkopal\ORM\ORM;

/**
 * Adds, changes and deletes floor elements for the app and MCP alike. Each edit runs in one
 * transaction: bump the project revision (with the optimistic check), validate and apply, log the change.
 * Deleting and copying a group or a variant set go to `GroupEditor` (several elements, one `ChangeBatch`).
 * The caller has checked access to the floor's project.
 */
final readonly class SceneEditor
{
	public function __construct(
		private ElementHandlers $handlers,
		private ChangeRecorder $changeRecorder,
		private ProjectRevisionService $revisionService,
		private GroupEditor $groupEditor,
		private VariantEditor $variantEditor,
		private ORM $orm,
	) {
	}

	/**
	 * @param array<string, mixed> $fields any subset of the type's editable fields; the rest are defaults
	 * @param AEntity|null $source the element a copy is made from, checked against like an update's current state
	 */
	public function create(
		User $user,
		Floor $floor,
		ElementTypeEnum $type,
		array $fields,
		?int $baseRevision = null,
		?AEntity $source = null,
	): EditResultDto
	{
		$handler = $this->handlers->get($type);

		return $this->transaction(function () use ($user, $floor, $handler, $fields, $baseRevision, $source): EditResultDto {
			$revision = $this->revisionService->bump($floor->project, $baseRevision);
			$state = $handler->normalize(
				$floor,
				self::editable($handler->fields(), $fields) + $handler->defaults($floor, $fields),
				$source,
			);
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

	/** Deleting a group deletes its items too (`ungroup` keeps them); deleting a variant set keeps its active variant. */
	public function delete(User $user, Floor $floor, ElementTypeEnum $type, int $id, ?int $baseRevision = null): EditResultDto
	{
		if ($type === ElementTypeEnum::Group) {
			return $this->groupEditor->deleteGroup($user, $floor, $id, $baseRevision);
		}
		if ($type === ElementTypeEnum::VariantSet) {
			return $this->variantEditor->deleteVariantSet($user, $floor, $id, $baseRevision);
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
			$name = self::editable(['name'], $fields)['name'] ?? null;

			return $this->groupEditor->duplicateGroup(
				$user,
				$floor,
				$id,
				GroupEditor::DuplicateShift,
				0,
				is_string($name) ? $name : null,
				$baseRevision,
			);
		}
		if ($type === ElementTypeEnum::VariantSet) {
			throw EditException::invalid('A variant set cannot be copied; copy a variant with add_variant instead.');
		}

		$element = $handler->find($floor, $id);
		if ($element === null || $handler->isDeleted($element)) {
			throw EditException::notFound(sprintf('%s %d not found on this floor.', $type->value, $id));
		}

		$copy = array_intersect_key($handler->state($element), array_flip($handler->fields()));
		// A copy beside the original: an item or roof a little east of it, a roof window along the ridge past it.
		if (($type === ElementTypeEnum::Item || $type === ElementTypeEnum::Roof) && is_int($copy['x'] ?? null)) {
			$copy['x'] += GroupEditor::DuplicateShift;
		}
		if ($type === ElementTypeEnum::RoofWindow && is_int($copy['along'] ?? null) && is_int($copy['width'] ?? null)) {
			$copy['along'] += $copy['width'] + GroupEditor::DuplicateShift;
		}

		// An item's copy is checked against the source: what the source shows (a picture file deleted since) stays allowed.
		$source = $type === ElementTypeEnum::Item ? $element : null;

		return $this->create($user, $floor, $type, self::editable($handler->fields(), $fields) + $copy, $baseRevision, $source);
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

	/** @param Closure(): EditResultDto $work */
	private function transaction(Closure $work): EditResultDto
	{
		return $this->orm->getTransactionProvider()->transaction($work);
	}
}
