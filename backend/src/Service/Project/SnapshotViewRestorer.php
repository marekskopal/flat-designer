<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\SavedViewSettingsDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\SavedView;
use FlatDesigner\Model\Entity\VariantSet;
use FlatDesigner\Model\Repository\SavedViewRepository;
use FlatDesigner\Service\Json\JsonDecoder;
use const JSON_THROW_ON_ERROR;

/**
 * The saved views in a snapshot: written as `views: [{floorId, name, settings}]` (the working copy's ids), and recreated
 * on restore on the restored floors with their variant sets and groups remapped. A snapshot from before saved views
 * has none; then the views of the working copy are kept on the restored floor `FloorMatcher` finds.
 */
final readonly class SnapshotViewRestorer
{
	public function __construct(private SavedViewRepository $viewRepository)
	{
	}

	/** @return list<array{floorId: int, name: string, settings: SavedViewSettingsDto}> */
	public function snapshot(Project $project): array
	{
		return array_map(static fn (SavedView $view): array => [
			'floorId' => $view->floor->id,
			'name' => $view->name,
			'settings' => SavedViewProvider::readSettings($view),
		], $this->viewRepository->findByProject($project->id));
	}

	/**
	 * The views of the working copy to keep, when the snapshot has none (read before its floors are replaced).
	 *
	 * @return list<array{level: int, floorName: string, name: string, settings: SavedViewSettingsDto}>
	 */
	public function keep(Project $project, SnapshotReader $snapshot): array
	{
		if ($snapshot->raw('views') !== null) {
			return [];
		}

		return array_map(static fn (SavedView $view): array => [
			'level' => $view->floor->level,
			'floorName' => $view->floor->name,
			'name' => $view->name,
			'settings' => SavedViewProvider::readSettings($view),
		], $this->viewRepository->findByProject($project->id));
	}

	/**
	 * @param list<array{level: int, floorName: string, name: string, settings: SavedViewSettingsDto}> $current from `keep()`
	 * @param array<int, Floor> $floors new floors by their id in the snapshot
	 * @param array<int, VariantSet> $sets new variant sets by their id in the snapshot
	 * @param array<int, ItemGroup> $groups new groups by their id in the snapshot
	 */
	public function restore(Project $project, SnapshotReader $snapshot, array $current, array $floors, array $sets, array $groups): void
	{
		if ($snapshot->raw('views') === null) {
			foreach ($current as $view) {
				$floor = FloorMatcher::like(array_values($floors), $view['level'], $view['floorName']);
				if ($floor === null) {
					continue;
				}

				// The variant sets are new: the view shows their active variants.
				$this->save($project, $floor, $view['name'], $view['settings']->withVariants([]));
			}

			return;
		}
		foreach ($snapshot->objects('views') as $data) {
			$floor = $floors[$data->int('floorId')] ?? null;
			if ($floor === null) {
				continue;
			}
			$settings = SavedViewSettingsDto::fromArray(
				JsonDecoder::decodeObject(json_encode($data->raw('settings'), JSON_THROW_ON_ERROR)),
			);
			$variants = [];
			foreach ($settings->variants as $setId => $groupId) {
				$set = $sets[$setId] ?? null;
				$group = $groups[$groupId] ?? null;
				if ($set !== null && $group !== null) {
					$variants[$set->id] = $group->id;
				}
			}
			$this->save($project, $floor, $data->string('name'), $settings->withVariants($variants));
		}
	}

	private function save(Project $project, Floor $floor, string $name, SavedViewSettingsDto $settings): void
	{
		$view = new SavedView(
			project: $project,
			floor: $floor,
			name: $name,
			settings: json_encode($settings, JSON_THROW_ON_ERROR),
			createdBy: null,
		);
		$view->createdAt = new DateTimeImmutable();
		$view->updatedAt = $view->createdAt;
		$this->viewRepository->persist($view);
	}
}
