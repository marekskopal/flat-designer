<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use Closure;
use DateTimeImmutable;
use FlatDesigner\Dto\ChangeDto;
use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectChange;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ProjectChangeRepository;
use FlatDesigner\Service\Actor\ActorContext;
use FlatDesigner\Service\Editor\Element\ElementHandler;
use FlatDesigner\Service\Editor\Element\State;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\Exception\UndoConflictException;
use FlatDesigner\Service\Project\ProjectRevisionService;
use JsonException;
use MarekSkopal\ORM\ORM;
use const JSON_THROW_ON_ERROR;

/**
 * Undo and redo of the current actor's own changes (a person's in the app, or one MCP client's), newest
 * first. A change is only reverted while its element is still exactly as the change left it; if someone
 * changed it since, the change is closed (skipped from then on) and the call answers 409. The changes of
 * a batch (e.g. a group move) are reverted together, and a conflict on any of them stops and closes all.
 */
final readonly class UndoService
{
	public function __construct(
		private ProjectChangeRepository $changeRepository,
		private ElementHandlers $handlers,
		private ProjectRevisionService $revisionService,
		private ActorContext $actorContext,
		private ORM $orm,
	) {
	}

	public function undo(User $user, Project $project): EditResultDto
	{
		return $this->closingConflicts(
			fn (): EditResultDto => $this->orm->getTransactionProvider()->transaction(function () use ($user, $project): EditResultDto {
				$change = $this->changeRepository->findUndoable($project->id, $user->id, $this->actorContext->getMcpClientId())
					?? throw EditException::conflict('Nothing to undo.');

				// A batch is undone backwards, so e.g. a group is back before its items need it.
				$batch = $this->batchOf($change);
				$reverted = [];
				$now = new DateTimeImmutable();
				foreach (array_reverse($batch) as $member) {
					$reverted[] = [$member, $this->revert($member, $member->after, $member->before, 'undo', $batch[0]->summary)];
					$member->undoneAt = $now;
				}

				return $this->finish($project, $reverted);
			}),
		);
	}

	public function redo(User $user, Project $project): EditResultDto
	{
		return $this->closingConflicts(
			fn (): EditResultDto => $this->orm->getTransactionProvider()->transaction(function () use ($user, $project): EditResultDto {
				$change = $this->changeRepository->findRedoable($project->id, $user->id, $this->actorContext->getMcpClientId())
					?? throw EditException::conflict('Nothing to redo.');

				$batch = $this->batchOf($change);
				$reverted = [];
				foreach ($batch as $member) {
					$reverted[] = [$member, $this->revert($member, $member->before, $member->after, 'redo', $batch[0]->summary)];
					$member->undoneAt = null;
				}

				return $this->finish($project, $reverted);
			}),
		);
	}

	/** @return non-empty-list<ProjectChange> the change, or all changes of its batch in the order they were made */
	private function batchOf(ProjectChange $change): array
	{
		if ($change->batch === null) {
			return [$change];
		}

		$batch = $this->changeRepository->findBatch($change->project->id, $change->batch);

		return $batch === [] ? [$change] : $batch;
	}

	/**
	 * Moves the element from state `$from` (where the change left it, or before it) to state `$to`; a
	 * null state means "deleted".
	 *
	 * @param string $summary of the change, or of the operation for a change in a batch
	 * @return array<string, mixed>|null the element's state afterwards
	 */
	private function revert(ProjectChange $change, ?string $from, ?string $to, string $action, string $summary): ?array
	{
		$handler = $this->handlers->get($change->elementType);
		$floor = $change->floor;
		$element = $floor === null ? null : $handler->find($floor, $change->elementId);
		$expected = self::decode($from);

		if ($floor === null || $element === null || !$this->isInState($handler, $element, $expected)) {
			throw new UndoConflictException($change, sprintf(
				'Cannot %s "%s": %s was changed by someone else since.',
				$action,
				$summary,
				$handler->label($expected ?? self::decode($to) ?? []),
			));
		}

		$target = self::decode($to);
		if ($target === null) {
			$handler->setDeleted($element, true);

			return null;
		}

		if ($handler->isDeleted($element)) {
			$handler->setDeleted($element, false);
		}

		// States logged before a field existed lack it; the element keeps its current value.
		$restorable = array_flip($handler->restorableFields());

		try {
			$state = $handler->normalize(
				$floor,
				array_intersect_key($target, $restorable) + array_intersect_key($handler->state($element), $restorable),
				$element,
			);
		} catch (EditException $e) {
			throw new UndoConflictException($change, sprintf('Cannot %s "%s": %s', $action, $summary, $e->getMessage()), $e);
		}

		$handler->update($element, $state);

		return $handler->state($element);
	}

	/**
	 * @param ElementHandler $handler
	 * @param array<string, mixed>|null $expected null = deleted
	 */
	private function isInState(ElementHandler $handler, AEntity $element, ?array $expected): bool
	{
		if ($expected === null) {
			return $handler->isDeleted($element);
		}

		// Fields added since the change was logged are not compared.
		return !$handler->isDeleted($element) && State::equals(array_intersect_key($handler->state($element), $expected), $expected);
	}

	/**
	 * One revision for the change or the whole batch.
	 *
	 * @param non-empty-list<array{ProjectChange, array<string, mixed>|null}> $reverted in the order applied
	 */
	private function finish(Project $project, array $reverted): EditResultDto
	{
		$revision = $this->revisionService->bump($project);
		$batch = [];
		foreach ($reverted as [$change, $element]) {
			$change->revision = $revision;
			$change->updatedAt = new DateTimeImmutable();
			$this->changeRepository->persist($change);
			$batch[$change->id] = ['change' => ChangeDto::fromEntity($change), 'element' => $element];
		}

		[$first, $element] = $reverted[0];
		if ($first->batch === null) {
			return new EditResultDto($revision, $batch[$first->id]['change'], $element);
		}

		// The batch's first change names the operation, whichever end it was reverted from.
		$head = $batch[min(array_keys($batch))];

		return new EditResultDto($revision, $head['change'], $head['element'], array_values($batch));
	}

	/**
	 * After a conflict the transaction is rolled back; the change is then closed on its own, so the next
	 * undo skips it instead of failing again.
	 *
	 * @param Closure(): EditResultDto $work
	 */
	private function closingConflicts(Closure $work): EditResultDto
	{
		try {
			return $work();
		} catch (UndoConflictException $e) {
			$change = $this->changeRepository->findById($e->change->id);
			if ($change !== null) {
				foreach ($this->batchOf($change) as $member) {
					$member->closed = true;
					$this->changeRepository->persist($member);
				}
			}

			throw new EditException($e->getMessage(), 409, $e);
		}
	}

	/** @return array<string, mixed>|null */
	private static function decode(?string $state): ?array
	{
		if ($state === null) {
			return null;
		}

		try {
			$decoded = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return null;
		}

		if (!is_array($decoded)) {
			return null;
		}

		/** @var array<string, mixed> $object */
		$object = $decoded;

		return $object;
	}
}
