<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Realtime;

use FlatDesigner\Model\Entity\Enum\ActorTypeEnum;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ProjectChangeRepository;
use FlatDesigner\Model\Repository\ProjectRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;
use const JSON_THROW_ON_ERROR;

/**
 * Live updates of projects over Mercure. Changes mark their project (`markChanged()`, from the revision
 * bump); the worker calls `flush()` after the response is sent, so updates go out only once the change is
 * committed. Each update carries the revision as committed plus the newest change's summary and actor.
 *
 * Topics are private: only subscribers whose cookie grants the topic (`MercureCookieIssuer`) get them.
 */
final class ProjectUpdatePublisher
{
	public const string TopicPrefix = 'flat-designer/projects/';

	/** @var array<int, int> project id => newest revision bumped in the current request */
	private array $changed = [];

	public function __construct(
		private readonly HubInterface $hub,
		private readonly ProjectRepository $projectRepository,
		private readonly ProjectChangeRepository $changeRepository,
		private readonly RealtimeOriginContext $originContext,
		private readonly LoggerInterface $logger,
	) {
	}

	public static function topic(int $projectId): string
	{
		return self::TopicPrefix . $projectId;
	}

	public function markChanged(int $projectId, int $revision): void
	{
		$this->changed[$projectId] = max($revision, $this->changed[$projectId] ?? 0);
	}

	/** Forgets marks of a request that is not going to be flushed (the worker resets before each request). */
	public function reset(): void
	{
		$this->changed = [];
	}

	public function flush(): void
	{
		$changed = $this->changed;
		$this->changed = [];

		foreach ($changed as $projectId => $revision) {
			try {
				$this->publish($projectId, $revision);
			} catch (Throwable $e) {
				// Live sync is best effort: a hub outage must never break the change that triggered it.
				$this->logger->warning('Mercure publish failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}
	}

	private function publish(int $projectId, int $bumpedRevision): void
	{
		$project = $this->projectRepository->findById($projectId);
		// A bump that was rolled back (rejected edit) never reached the database: nothing to announce.
		if ($project === null || $project->revision < $bumpedRevision) {
			return;
		}

		// The newest change, if it produced this revision (floor edits and restores log none).
		$change = $this->changeRepository->findRecent($projectId, 1)[0] ?? null;
		if ($change !== null && $change->revision !== $project->revision) {
			$change = null;
		}

		$this->hub->publish(new Update(self::topic($projectId), json_encode([
			'type' => 'projectChanged',
			'projectId' => $projectId,
			'revision' => $project->revision,
			'floorId' => $change?->floor?->id,
			'summary' => $change?->summary,
			'undone' => $change?->undoneAt !== null,
			'actorType' => $change?->actorType->value,
			'actorName' => $change === null ? null : self::actorName($change->actorType, $change->mcpClientName, $change->user),
			'originClientId' => $this->originContext->get(),
		], JSON_THROW_ON_ERROR), private: true));
	}

	private static function actorName(ActorTypeEnum $type, ?string $clientName, ?User $user): string
	{
		return $type === ActorTypeEnum::Agent ? $clientName ?? 'Agent' : $user->name ?? 'Someone';
	}
}
