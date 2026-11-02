<?php

declare(strict_types=1);

namespace Izbik\Service\Editor;

use Izbik\Model\Entity\Enum\ChangeOperationEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\ProjectChange;
use Izbik\Model\Entity\User;
use Izbik\Model\Repository\ProjectChangeRepository;
use Izbik\Service\Actor\ActorContext;
use Izbik\Service\Editor\Element\ElementHandler;
use Izbik\Service\Editor\Element\State;
use const JSON_THROW_ON_ERROR;

/** Writes the change log; a new change drops the actor's redo history. */
final readonly class ChangeRecorder
{
	/** Fields whose change alone reads as a move or a turn. */
	private const array PositionFields = ['x', 'y', 'z', 'startX', 'startY', 'endX', 'endY', 'offset'];

	public function __construct(private ProjectChangeRepository $changeRepository, private ActorContext $actorContext)
	{
	}

	/**
	 * @param ElementHandler $handler
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed>|null $after
	 * @param string|null $batch shared by the changes of one operation, see `ChangeBatch`
	 * @param string|null $summary instead of the one derived from the states (the first change of a batch names the operation)
	 */
	public function record(
		User $user,
		Floor $floor,
		ElementHandler $handler,
		ChangeOperationEnum $operation,
		int $elementId,
		?array $before,
		?array $after,
		int $revision,
		?string $batch = null,
		?string $summary = null,
	): ProjectChange {
		foreach ($this->changeRepository->findRedoables(
			$floor->project->id,
			$user->id,
			$this->actorContext->getMcpClientId(),
		) as $redoable) {
			$redoable->closed = true;
			$this->changeRepository->schedulePersist($redoable);
		}

		$change = State::stamp(new ProjectChange(
			project: $floor->project,
			floor: $floor,
			user: $user,
			actorType: $this->actorContext->getActorType(),
			mcpClientId: $this->actorContext->getMcpClientId(),
			mcpClientName: $this->actorContext->getMcpClientName(),
			operation: $operation,
			elementType: $handler->type(),
			elementId: $elementId,
			summary: mb_substr($summary ?? self::summary($handler, $operation, $before, $after), 0, 255),
			before: $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
			after: $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
			revision: $revision,
		));
		$change->batch = $batch;
		$this->changeRepository->persist($change);

		return $change;
	}

	/**
	 * "Added item "Base 60"", "Moved wall "South"", "Changed room "WC" (name, ceilingHeight)".
	 *
	 * @param ElementHandler $handler
	 * @param array<string, mixed>|null $before
	 * @param array<string, mixed>|null $after
	 */
	private static function summary(ElementHandler $handler, ChangeOperationEnum $operation, ?array $before, ?array $after): string
	{
		$label = $handler->label($after ?? $before ?? []);
		if ($operation === ChangeOperationEnum::Create) {
			return 'Added ' . $label;
		}
		if ($operation === ChangeOperationEnum::Delete) {
			return 'Deleted ' . $label;
		}

		$changed = [];
		foreach ($handler->fields() as $field) {
			if (!State::equals($before[$field] ?? null, $after[$field] ?? null)) {
				$changed[] = $field;
			}
		}

		$summary = match (true) {
			$changed === ['rotation'] => 'Rotated ',
			$changed !== [] && array_diff($changed, self::PositionFields) === [] => 'Moved ',
			default => 'Changed ',
		};
		$summary .= $label;
		if ($summary === 'Changed ' . $label && $changed !== []) {
			$summary .= ' (' . implode(', ', $changed) . ')';
		}

		return mb_substr($summary, 0, 255);
	}
}
