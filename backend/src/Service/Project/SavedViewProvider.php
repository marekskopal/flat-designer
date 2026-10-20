<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Project;

use DateTimeImmutable;
use FlatDesigner\Dto\SavedViewDto;
use FlatDesigner\Dto\SavedViewInputDto;
use FlatDesigner\Dto\SavedViewSettingsDto;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Entity\SavedView;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\SavedViewRepository;
use FlatDesigner\Model\Repository\VariantSetRepository;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Project\Exception\ProjectException;
use const JSON_THROW_ON_ERROR;

/** The saved views of a project: named views of its floors, e.g. for screenshots. They are not floor elements: no revision. */
final readonly class SavedViewProvider
{
	private const int MaxNameLength = 100;

	public function __construct(
		private SavedViewRepository $viewRepository,
		private FloorRepository $floorRepository,
		private VariantSetRepository $variantSetRepository,
		private ItemGroupRepository $groupRepository,
	) {
	}

	/** @return list<SavedView> by name */
	public function list(Project $project): array
	{
		return $this->viewRepository->findByProject($project->id);
	}

	public function find(Project $project, int $viewId): ?SavedView
	{
		return $this->viewRepository->findOneInProject($project->id, $viewId);
	}

	public function findByName(Project $project, string $name): ?SavedView
	{
		$name = trim($name);
		foreach ($this->list($project) as $view) {
			if ($view->name === $name) {
				return $view;
			}
		}

		return null;
	}

	public function create(?User $author, Project $project, SavedViewInputDto $input): SavedView
	{
		$floor = $this->floor($project, $input->floorId);
		$now = new DateTimeImmutable();
		$view = new SavedView(
			project: $project,
			floor: $floor,
			name: self::name($input->name),
			settings: $this->settings($floor, $input->settings),
			createdBy: $author,
		);
		$view->createdAt = $now;
		$view->updatedAt = $now;
		$this->viewRepository->persist($view);

		return $view;
	}

	public function update(SavedView $view, SavedViewInputDto $input): SavedView
	{
		$floor = $this->floor($view->project, $input->floorId);
		$view->floor = $floor;
		$view->name = self::name($input->name);
		$view->settings = $this->settings($floor, $input->settings);
		$view->updatedAt = new DateTimeImmutable();
		$this->viewRepository->persist($view);

		return $view;
	}

	public function delete(SavedView $view): void
	{
		$this->viewRepository->delete($view);
	}

	public function toDto(SavedView $view): SavedViewDto
	{
		return SavedViewDto::fromEntity($view, self::readSettings($view));
	}

	public static function readSettings(SavedView $view): SavedViewSettingsDto
	{
		return SavedViewSettingsDto::fromArray(JsonDecoder::decodeObject($view->settings));
	}

	private function floor(Project $project, int $floorId): Floor
	{
		return $this->floorRepository->findOneInProject($project->id, $floorId)
			?? throw new ProjectException(sprintf('Floor %d is not a floor of this project.', $floorId), 422);
	}

	private static function name(string $name): string
	{
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MaxNameLength) {
			throw new ProjectException(sprintf('A view needs a name of at most %d characters.', self::MaxNameLength), 422);
		}

		return $name;
	}

	/** Checked against the floor: the wall cut's range, and variant sets of the floor with one of their groups. */
	private function settings(Floor $floor, SavedViewSettingsDto $settings): string
	{
		$cut = $settings->sectionHeight;
		if ($cut !== null && ($cut < ShareLinkProvider::MinSectionHeight || $cut > ShareLinkProvider::MaxSectionHeight)) {
			throw new ProjectException(sprintf(
				'Wall cut must be between %d and %d mm.',
				ShareLinkProvider::MinSectionHeight,
				ShareLinkProvider::MaxSectionHeight,
			), 422);
		}
		foreach ($settings->variants as $setId => $groupId) {
			$set = $this->variantSetRepository->findOnFloor($floor->id, $setId);
			$group = $set === null ? null : $this->groupRepository->findOnFloor($floor->id, $groupId);
			if ($set === null || $group?->variantSet?->id !== $set->id) {
				throw new ProjectException(sprintf('Group %d is not a variant of set %d on this floor.', $groupId, $setId), 422);
			}
		}

		return json_encode($settings, JSON_THROW_ON_ERROR);
	}
}
