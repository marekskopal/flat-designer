<?php

declare(strict_types=1);

namespace Izbik\Service\Project;

use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Item;
use Izbik\Model\Entity\Project;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Model\Repository\ItemRepository;
use Izbik\Model\Repository\ProjectFileRepository;
use Izbik\Service\Furniture\ImageParameters;
use Izbik\Service\Json\JsonDecoder;

/** The project files that `Custom` items show as pictures (through their definitions' `image` parameters). */
final readonly class ShownPictures
{
	public function __construct(
		private FloorRepository $floorRepository,
		private ItemRepository $itemRepository,
		private ProjectFileRepository $fileRepository,
	) {
	}

	/**
	 * The ids of the project's files the items show (a file deleted since is not listed: the item draws a plain picture),
	 * ascending; each definition is decoded once.
	 *
	 * @param list<Item> $items
	 * @return list<int>
	 */
	public function byItems(Project $project, array $items): array
	{
		return $this->fileRepository->findIdsInProject($project->id, $this->referenced($items));
	}

	/** Whether any item of the project (on any floor) shows the file. */
	public function isShownIn(Project $project, int $fileId): bool
	{
		$floorIds = array_map(static fn (Floor $floor): int => $floor->id, $this->floorRepository->findByProject($project->id));

		return in_array($fileId, $this->referenced($this->itemRepository->findWithDefinitionOnFloors($floorIds)), true);
	}

	/**
	 * @param list<Item> $items
	 * @return list<int>
	 */
	private function referenced(array $items): array
	{
		$keys = [];
		$fileIds = [];
		foreach ($items as $item) {
			if ($item->definition === null) {
				continue;
			}
			$keys[$item->definition->id] ??= ImageParameters::keys(JsonDecoder::decodeObject($item->definition->definition));
			$params = $item->params === null ? [] : JsonDecoder::decodeObject($item->params);
			foreach (ImageParameters::fileIdsOf($keys[$item->definition->id], $params['values'] ?? null) as $fileId) {
				$fileIds[$fileId] = $fileId;
			}
		}
		ksort($fileIds);

		return array_values($fileIds);
	}
}
