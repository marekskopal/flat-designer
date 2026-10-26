<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\SharedViewDto;
use FlatDesigner\Dto\ShareLinkDto;
use FlatDesigner\Dto\ShareLinkInputDto;
use FlatDesigner\Dto\ShareSettingsDto;
use FlatDesigner\Model\Entity\Enum\ShareViewEnum;
use FlatDesigner\Model\Entity\ModelAsset;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\ProjectFile;
use FlatDesigner\Model\Entity\ShareLink;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\ModelAssetRepository;
use FlatDesigner\Model\Repository\ShareLinkRepository;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Project\Exception\ProjectException;
use MarekSkopal\ORM\ORM;
use stdClass;
use const JSON_THROW_ON_ERROR;

/**
 * Share links of a project. Creating one saves a version ("Shared: <name>"), so the guest sees the floor as it
 * was then; later edits of the working copy do not reach the link until it is refreshed, which saves a new version for
 * the same URL. Deleting a link keeps its version.
 */
final readonly class ShareLinkProvider
{
	private const int MaxNameLength = 100;

	/** The wall cut a view may start with, mm above the floor (saved views use the same range). */
	public const int MinSectionHeight = 900;
	public const int MaxSectionHeight = 2700;

	public function __construct(
		private ShareLinkRepository $shareLinkRepository,
		private FloorRepository $floorRepository,
		private ProjectVersionProvider $versionProvider,
		private ProjectSnapshotService $snapshotService,
		private ProjectFileProvider $fileProvider,
		private ModelAssetRepository $modelRepository,
		private SharedAssetIds $assetIds,
		private ORM $orm,
	) {
	}

	/** @return list<ShareLink> Newest first. */
	public function list(Project $project): array
	{
		return $this->shareLinkRepository->findByProject($project->id);
	}

	public function find(Project $project, int $shareLinkId): ?ShareLink
	{
		return $this->shareLinkRepository->findOneInProject($project->id, $shareLinkId);
	}

	public function create(User $author, Project $project, ShareLinkInputDto $input): ShareLink
	{
		$floor = $input->floorId === null ? null : $this->floorRepository->findOneInProject($project->id, $input->floorId);
		if ($floor === null) {
			throw new ProjectException('Floor not found in this project.');
		}
		$name = self::name($input->name);
		$settings = self::settings($input->settings);
		$expiresAt = self::expiresAt($input->expiresAt);

		return $this->orm->getTransactionProvider()->transaction(
			function () use ($author, $project, $floor, $name, $settings, $expiresAt): ShareLink {
				$link = new ShareLink(
					project: $project,
					version: $this->versionProvider->create($author, $project, 'Shared: ' . $name),
					floorId: $floor->id,
					floorName: $floor->name,
					token: bin2hex(random_bytes(16)),
					name: $name,
					settings: $settings,
					expiresAt: $expiresAt,
					createdBy: $author,
				);
				$link->createdAt = new DateTimeImmutable();
				$link->updatedAt = $link->createdAt;
				$this->shareLinkRepository->persist($link);

				return $link;
			},
		);
	}

	/** Changes the name, settings and expiry; the shared floor and its version stay. */
	public function update(ShareLink $link, ShareLinkInputDto $input): ShareLink
	{
		$link->name = self::name($input->name);
		$link->settings = self::settings($input->settings);
		// An unchanged expiry is kept even when it has passed (renaming an expired link).
		if ($input->expiresAt?->getTimestamp() !== $link->expiresAt?->getTimestamp()) {
			$link->expiresAt = self::expiresAt($input->expiresAt);
		}
		$link->updatedAt = new DateTimeImmutable();
		$this->shareLinkRepository->persist($link);

		return $link;
	}

	/**
	 * Saves a new version ("Shared: <name>") and points the link at it, so guests see the floor as it is now; the
	 * token, name, settings and expiry stay. The previous version stays in the history.
	 */
	public function refresh(User $author, ShareLink $link): ShareLink
	{
		// Floors keep their ids in snapshots, so the shared floor is the working copy's floor of the same id.
		$floor = $this->floorRepository->findOneInProject($link->project->id, $link->floorId);
		if ($floor === null) {
			throw new ProjectException('The shared floor is no longer in the project; create a new link instead.');
		}

		return $this->orm->getTransactionProvider()->transaction(function () use ($author, $link, $floor): ShareLink {
			$link->version = $this->versionProvider->create($author, $link->project, 'Shared: ' . $link->name);
			$link->floorName = $floor->name;
			$link->updatedAt = new DateTimeImmutable();
			$this->shareLinkRepository->persist($link);

			return $link;
		});
	}

	public function delete(ShareLink $link): void
	{
		$this->shareLinkRepository->delete($link);
	}

	/** The guest's view of a link; null when the token is unknown or the link expired. */
	public function findSharedView(string $token): ?SharedViewDto
	{
		$link = $this->activeLink($token);
		if ($link === null) {
			return null;
		}
		['scene' => $scene, 'below' => $below] = $this->snapshotService->getFloorScenes($link->version->snapshot, $link->floorId);
		if ($scene === null) {
			return null;
		}
		$settings = self::readSettings($link);

		return new SharedViewDto(
			name: $link->name,
			projectName: $link->project->name,
			settings: $settings,
			scene: $scene,
			stack: $settings->allFloors ? $below : [],
		);
	}

	/** A 3D model a guest's view draws (listed in its scene's `models`); null for any other. */
	public function findSharedModel(string $token, int $modelId): ?ModelAsset
	{
		$shared = $this->sharedAssets($token);

		return $shared !== null && in_array($modelId, $shared[1]['models'], true) ? $this->modelRepository->findById($modelId) : null;
	}

	/** An image file a guest's view shows as a picture (listed in its scene's `pictures`); null for any other. */
	public function findSharedPicture(string $token, int $fileId): ?ProjectFile
	{
		$shared = $this->sharedAssets($token);
		$file = $shared !== null && in_array($fileId, $shared[1]['pictures'], true)
			? $this->fileProvider->find($shared[0]->project, $fileId)
			: null;

		return $file !== null && $this->fileProvider->isImage($file) ? $file : null;
	}

	/** The link of a token while it works (known, not expired); null otherwise. */
	public function activeLink(string $token): ?ShareLink
	{
		$link = $this->shareLinkRepository->findOneByToken($token);

		return $link === null || $link->isExpired(new DateTimeImmutable()) ? null : $link;
	}

	/**
	 * The link and the ids of the models and pictures its floor draws (with `allFloors`, also the floors under it),
	 * without decoding the snapshot each time.
	 *
	 * @return array{ShareLink, array{models: list<int>, pictures: list<int>}}|null
	 */
	private function sharedAssets(string $token): ?array
	{
		$link = $this->activeLink($token);
		if ($link === null) {
			return null;
		}
		$version = $link->version;
		$ids = $this->assetIds->of(
			$version->id,
			$link->floorId,
			fn (): ?stdClass => $this->snapshotService->getFloorScene($version->snapshot, $link->floorId),
		);
		if ($ids !== null && self::readSettings($link)->allFloors) {
			$below = $this->assetIds->below(
				$version->id,
				$link->floorId,
				fn (): array => $this->snapshotService->getFloorScenes($version->snapshot, $link->floorId)['below'],
			);
			$ids = ['models' => [...$ids['models'], ...$below['models']], 'pictures' => [...$ids['pictures'], ...$below['pictures']]];
		}

		return $ids === null ? null : [$link, $ids];
	}

	/** @param string|null $baseUrl the app's origin, to include the link's URL */
	public function toDto(ShareLink $link, ?string $baseUrl = null): ShareLinkDto
	{
		return ShareLinkDto::fromEntity($link, self::readSettings($link), $baseUrl);
	}

	public static function readSettings(ShareLink $link): ShareSettingsDto
	{
		return ShareSettingsDto::fromArray(JsonDecoder::decodeObject($link->settings));
	}

	private static function name(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MaxNameLength) {
			throw new ProjectException(sprintf('Name is required (at most %d characters).', self::MaxNameLength));
		}

		return $name;
	}

	/** Validates the settings and returns their JSON. */
	private static function settings(ShareSettingsDto $settings): string
	{
		if ($settings->views === []) {
			throw new ProjectException('Allow at least one view.');
		}
		if (count(array_unique(array_map(fn (ShareViewEnum $view): string => $view->value, $settings->views))) !== count(
			$settings->views,
		)) {
			throw new ProjectException('Each view may be listed once.');
		}
		if (!in_array(ShareViewEnum::Overview, $settings->views, true) && !in_array(ShareViewEnum::Top, $settings->views, true)) {
			throw new ProjectException('Allow the 3D or the plan view: the walk-through needs one to return to.');
		}
		if (!in_array($settings->initialView, $settings->views, true)) {
			throw new ProjectException('The start view must be one of the allowed views.');
		}
		if ($settings->camera !== null && !in_array(ShareViewEnum::Overview, $settings->views, true)) {
			throw new ProjectException('A camera position needs the 3D view allowed.');
		}
		if (
			$settings->sectionHeight !== null
			&& (
				$settings->sectionHeight < self::MinSectionHeight
				|| $settings->sectionHeight > self::MaxSectionHeight
			)
		) {
			throw new ProjectException(sprintf('Wall cut must be between %d and %d mm.', self::MinSectionHeight, self::MaxSectionHeight));
		}

		return json_encode($settings, JSON_THROW_ON_ERROR);
	}

	private static function expiresAt(?DateTimeImmutable $expiresAt): ?DateTimeImmutable
	{
		if ($expiresAt !== null && $expiresAt <= new DateTimeImmutable()) {
			throw new ProjectException('The expiry must be in the future.');
		}

		return $expiresAt;
	}
}
