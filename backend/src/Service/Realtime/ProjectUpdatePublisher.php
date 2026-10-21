<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Realtime;

use FlatDesigner\Model\Entity\Enum\ActorTypeEnum;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\ItemRepository;
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
 * An edited furniture definition (`markDefinitionChanged()`) sends `definitionChanged` to every project with items
 * drawn from it: their look changes although no project revision does. A changed material (`markMaterialChanged()`) sends
 * `materialChanged` to every project: the catalogue is global and used from too many places to look them up.
 * Changed project details (`markDetailsChanged()`: name, address, description) send `detailsChanged`; they are not
 * floor content, so they bump no revision either. A saved price list (`markPricesChanged()`) sends `pricesChanged`
 * for the same reason.
 *
 * Topics are private: only subscribers whose cookie grants the topic (`MercureCookieIssuer`) get them.
 */
final class ProjectUpdatePublisher
{
	public const string TopicPrefix = 'flat-designer/projects/';

	/** @var array<int, int> project id => newest revision bumped in the current request */
	private array $changed = [];

	/** @var array<int, true> furniture definitions edited in the current request */
	private array $changedDefinitions = [];

	/** @var array<int, true> materials changed in the current request */
	private array $changedMaterials = [];

	/** @var array<int, true> projects whose details changed in the current request */
	private array $changedDetails = [];

	/** @var array<int, true> projects whose price list was saved in the current request */
	private array $changedPrices = [];

	public function __construct(
		private readonly HubInterface $hub,
		private readonly ProjectRepository $projectRepository,
		private readonly ProjectChangeRepository $changeRepository,
		private readonly ItemRepository $itemRepository,
		private readonly FloorRepository $floorRepository,
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

	public function markDefinitionChanged(int $definitionId): void
	{
		$this->changedDefinitions[$definitionId] = true;
	}

	public function markMaterialChanged(int $materialId): void
	{
		$this->changedMaterials[$materialId] = true;
	}

	public function markDetailsChanged(int $projectId): void
	{
		$this->changedDetails[$projectId] = true;
	}

	public function markPricesChanged(int $projectId): void
	{
		$this->changedPrices[$projectId] = true;
	}

	/** Forgets marks of a request that is not going to be flushed (the worker resets before each request). */
	public function reset(): void
	{
		$this->changed = [];
		$this->changedDefinitions = [];
		$this->changedMaterials = [];
		$this->changedDetails = [];
		$this->changedPrices = [];
	}

	public function flush(): void
	{
		$changed = $this->changed;
		$definitions = array_keys($this->changedDefinitions);
		$materials = array_keys($this->changedMaterials);
		$details = array_keys($this->changedDetails);
		$prices = array_keys($this->changedPrices);
		$this->changed = [];
		$this->changedDefinitions = [];
		$this->changedMaterials = [];
		$this->changedDetails = [];
		$this->changedPrices = [];

		foreach ($definitions as $definitionId) {
			try {
				$this->publishDefinition($definitionId);
			} catch (Throwable $e) {
				$this->logger->warning('Mercure publish failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}

		foreach ($materials as $materialId) {
			try {
				$this->publishMaterial($materialId);
			} catch (Throwable $e) {
				$this->logger->warning('Mercure publish failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}

		foreach ($details as $projectId) {
			try {
				$this->publishSimple('detailsChanged', $projectId);
			} catch (Throwable $e) {
				$this->logger->warning('Mercure publish failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}

		foreach ($prices as $projectId) {
			try {
				$this->publishSimple('pricesChanged', $projectId);
			} catch (Throwable $e) {
				$this->logger->warning('Mercure publish failed: ' . $e->getMessage(), ['exception' => $e]);
			}
		}

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

	private function publishDefinition(int $definitionId): void
	{
		$projectIds = $this->floorRepository->findProjectIds($this->itemRepository->findFloorIdsByDefinition($definitionId));
		foreach ($projectIds as $projectId) {
			$this->hub->publish(new Update(self::topic($projectId), json_encode([
				'type' => 'definitionChanged',
				'projectId' => $projectId,
				'definitionId' => $definitionId,
				'originClientId' => $this->originContext->get(),
			], JSON_THROW_ON_ERROR), private: true));
		}
	}

	private function publishMaterial(int $materialId): void
	{
		foreach ($this->projectRepository->findAllIds() as $projectId) {
			$this->hub->publish(new Update(self::topic($projectId), json_encode([
				'type' => 'materialChanged',
				'projectId' => $projectId,
				'materialId' => $materialId,
				'originClientId' => $this->originContext->get(),
			], JSON_THROW_ON_ERROR), private: true));
		}
	}

	/** An update that carries only its type: the project's details or prices changed. */
	private function publishSimple(string $type, int $projectId): void
	{
		$this->hub->publish(new Update(self::topic($projectId), json_encode([
			'type' => $type,
			'projectId' => $projectId,
			'originClientId' => $this->originContext->get(),
		], JSON_THROW_ON_ERROR), private: true));
	}

	private static function actorName(ActorTypeEnum $type, ?string $clientName, ?User $user): string
	{
		return $type === ActorTypeEnum::Agent ? $clientName ?? 'Agent' : $user->name ?? 'Someone';
	}
}
