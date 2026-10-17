<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use Closure;
use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ChangeOperationEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Service\Editor\Element\ElementHandler;
use FlatDesigner\Service\Editor\Element\State;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Project\ProjectRevisionService;
use MarekSkopal\ORM\ORM;

/**
 * Building blocks of an operation that changes several elements (see `GroupEditor`): one transaction, one
 * revision bump, and every element change validated by its handler and logged into the `ChangeBatch`.
 */
final readonly class BatchWriter
{
	public function __construct(private ChangeRecorder $changeRecorder, private ProjectRevisionService $revisionService, private ORM $orm)
	{
	}

	/** @param Closure(): EditResultDto $work */
	public function transaction(Closure $work): EditResultDto
	{
		return $this->orm->getTransactionProvider()->transaction($work);
	}

	/** Bumps the revision (with the optimistic check) for the whole batch. */
	public function start(Floor $floor, ?int $baseRevision, string $summary): ChangeBatch
	{
		return new ChangeBatch($this->revisionService->bump($floor->project, $baseRevision), $summary);
	}

	/** @param array<string, mixed> $state normalised */
	public function create(User $user, Floor $floor, ChangeBatch $batch, ElementHandler $handler, array $state): AEntity
	{
		$element = $handler->create($floor, $state);
		$this->record($user, $floor, $batch, $handler, ChangeOperationEnum::Create, $element, null);

		return $element;
	}

	/**
	 * Changes the given fields; a change that alters nothing is not logged.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function update(User $user, Floor $floor, ChangeBatch $batch, ElementHandler $handler, AEntity $element, array $fields): void
	{
		$before = $handler->state($element);
		$state = $handler->normalize($floor, $fields + $before, $element);
		if (State::equals(array_intersect_key($state, $before), array_intersect_key($before, $state))) {
			return;
		}

		$handler->update($element, $state);
		$this->record($user, $floor, $batch, $handler, ChangeOperationEnum::Update, $element, $before);
	}

	public function delete(User $user, Floor $floor, ChangeBatch $batch, ElementHandler $handler, AEntity $element): void
	{
		$before = $handler->state($element);
		$handler->setDeleted($element, true);
		$this->record($user, $floor, $batch, $handler, ChangeOperationEnum::Delete, $element, $before);
	}

	/** The element on the floor; 404 when it is not there or deleted. */
	public function existing(Floor $floor, ElementHandler $handler, int $id): AEntity
	{
		$element = $handler->find($floor, $id);
		if ($element === null || $handler->isDeleted($element)) {
			throw EditException::notFound(sprintf('%s %d not found on this floor.', $handler->type()->value, $id));
		}

		return $element;
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
}
