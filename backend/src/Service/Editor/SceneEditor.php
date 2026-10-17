<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use Closure;
use FlatDesigner\Dto\ChangeDto;
use FlatDesigner\Dto\EditResultDto;
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
 * The caller has checked access to the floor's project.
 */
final readonly class SceneEditor
{
	public function __construct(
		private ElementHandlers $handlers,
		private ChangeRecorder $changeRecorder,
		private ProjectRevisionService $revisionService,
		private ORM $orm,
	) {
	}

	/** @param array<string, mixed> $fields any subset of the type's editable fields; the rest are defaults */
	public function create(User $user, Floor $floor, ElementTypeEnum $type, array $fields, ?int $baseRevision = null): EditResultDto
	{
		$handler = $this->handlers->get($type);

		return $this->transaction(function () use ($user, $floor, $handler, $fields, $baseRevision): EditResultDto {
			$revision = $this->revisionService->bump($floor->project, $baseRevision);
			$state = $handler->normalize($floor, self::editable($handler->fields(), $fields) + $handler->defaults(), null);
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

	public function delete(User $user, Floor $floor, ElementTypeEnum $type, int $id, ?int $baseRevision = null): EditResultDto
	{
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
	 * shifted 100 mm along x, other copies stay in place (an opening copy needs another offset).
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
		$element = $handler->find($floor, $id);
		if ($element === null || $handler->isDeleted($element)) {
			throw EditException::notFound(sprintf('%s %d not found on this floor.', $type->value, $id));
		}

		$copy = array_intersect_key($handler->state($element), array_flip($handler->fields()));
		if ($type === ElementTypeEnum::Item && is_int($copy['x'] ?? null)) {
			$copy['x'] += 100;
		}

		return $this->create($user, $floor, $type, self::editable($handler->fields(), $fields) + $copy, $baseRevision);
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
