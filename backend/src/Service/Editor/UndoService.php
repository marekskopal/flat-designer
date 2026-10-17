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
 * changed it since, the change is closed (skipped from then on) and the call answers 409.
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

				$element = $this->revert($change, $change->after, $change->before, 'undo');
				$change->undoneAt = new DateTimeImmutable();

				return $this->finish($project, $change, $element);
			}),
		);
	}

	public function redo(User $user, Project $project): EditResultDto
	{
		return $this->closingConflicts(
			fn (): EditResultDto => $this->orm->getTransactionProvider()->transaction(function () use ($user, $project): EditResultDto {
				$change = $this->changeRepository->findRedoable($project->id, $user->id, $this->actorContext->getMcpClientId())
					?? throw EditException::conflict('Nothing to redo.');

				$element = $this->revert($change, $change->before, $change->after, 'redo');
				$change->undoneAt = null;

				return $this->finish($project, $change, $element);
			}),
		);
	}

	/**
	 * Moves the element from state `$from` (where the change left it, or before it) to state `$to`; a
	 * null state means "deleted".
	 *
	 * @return array<string, mixed>|null the element's state afterwards
	 */
	private function revert(ProjectChange $change, ?string $from, ?string $to, string $action): ?array
	{
		$handler = $this->handlers->get($change->elementType);
		$floor = $change->floor;
		$element = $floor === null ? null : $handler->find($floor, $change->elementId);
		$expected = self::decode($from);

		if ($floor === null || $element === null || !$this->isInState($handler, $element, $expected)) {
			throw new UndoConflictException($change, sprintf(
				'Cannot %s "%s": %s was changed by someone else since.',
				$action,
				$change->summary,
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

		try {
			$state = $handler->normalize($floor, array_intersect_key($target, array_flip($handler->restorableFields())), $element);
		} catch (EditException $e) {
			throw new UndoConflictException($change, sprintf('Cannot %s "%s": %s', $action, $change->summary, $e->getMessage()), $e);
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

		return !$handler->isDeleted($element) && State::equals($handler->state($element), $expected);
	}

	/** @param array<string, mixed>|null $element */
	private function finish(Project $project, ProjectChange $change, ?array $element): EditResultDto
	{
		$revision = $this->revisionService->bump($project);
		$change->revision = $revision;
		$change->updatedAt = new DateTimeImmutable();
		$this->changeRepository->persist($change);

		return new EditResultDto($revision, ChangeDto::fromEntity($change), $element);
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
				$change->closed = true;
				$this->changeRepository->persist($change);
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
