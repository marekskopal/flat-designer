<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Furniture\CutList;

use FlatDesigner\Dto\CutListDto;
use FlatDesigner\Dto\ItemDto;
use FlatDesigner\Dto\MaterialDto;
use FlatDesigner\Dto\SceneDto;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Project;
use FlatDesigner\Model\Repository\FloorRepository;
use FlatDesigner\Service\Furniture\DefinitionEvaluator;
use FlatDesigner\Service\Furniture\DefinitionException;
use FlatDesigner\Service\Furniture\ResolvedPart;
use FlatDesigner\Service\Json\JsonDecoder;
use FlatDesigner\Service\Provider\SceneProvider;
use const JSON_THROW_ON_ERROR;

/**
 * The cut list of the Custom items of a floor or a whole project (working copy, shown variants only): each item's
 * definition evaluated at its size and values, the parts in their materials — the item's choice for the slot, the
 * item material for the first slot, otherwise the slot's default, the item material for a slot without one — as the
 * viewer draws them (`partMaterial()`).
 */
final readonly class CutListService
{
	/** Part types cut from sheet material. */
	private const array BoardTypes = ['board', 'front'];

	public function __construct(
		private SceneProvider $sceneProvider,
		private FloorRepository $floorRepository,
		private DefinitionEvaluator $evaluator,
	) {
	}

	public function forProject(Project $project): CutListDto
	{
		$floors = $this->floorRepository->findByProject($project->id);

		return $this->forScenes(array_map(fn (Floor $floor): array => [$floor, $this->sceneProvider->getScene($floor)], $floors));
	}

	public function forFloor(Floor $floor): CutListDto
	{
		return $this->forScenes([[$floor, $this->sceneProvider->getScene($floor)]]);
	}

	/**
	 * The cut list of floors whose scenes are built already (the quantities read them too).
	 *
	 * @param list<array{Floor, SceneDto}> $scenes
	 */
	public function forScenes(array $scenes): CutListDto
	{
		$list = new CutListAccumulator();
		foreach ($scenes as [$floor, $scene]) {
			$this->addFloor($list, $floor, $scene);
		}

		return $list->result();
	}

	private function addFloor(CutListAccumulator $list, Floor $floor, SceneDto $scene): void
	{
		$hidden = array_flip($scene->hiddenItemIds());
		$definitions = self::definitions($scene);
		$materials = [];
		foreach ($scene->materials as $material) {
			$materials[$material->code] = $material;
		}

		foreach ($scene->items as $item) {
			if ($item->kind === ItemKindEnum::Custom && !isset($hidden[$item->id])) {
				$this->addItem($list, $item, $definitions[$item->definitionId ?? 0] ?? null, $floor, $materials, $scene->materials);
			}
		}
	}

	/**
	 * @param array<mixed>|null $definition
	 * @param array<string, MaterialDto> $byCode
	 * @param list<MaterialDto> $all
	 */
	private function addItem(CutListAccumulator $list, ItemDto $item, ?array $definition, Floor $floor, array $byCode, array $all): void
	{
		if ($definition === null) {
			$list->skip($item->id, $item->name, $floor->name, 'The furniture definition is gone.');
			return;
		}

		$params = self::params($item);
		$values = is_array($params['values'] ?? null) ? $params['values'] : [];

		try {
			$parts = $this->evaluator->evaluate($definition, $item->width, $item->depth, $item->height, $values);
		} catch (DefinitionException $e) {
			$list->skip($item->id, $item->name, $floor->name, $e->getMessage());
			return;
		}

		$slots = is_array($definition['materialSlots'] ?? null) ? array_values($definition['materialSlots']) : [];
		foreach ($parts as $part) {
			$material = self::partMaterial($part, $item, $params, $slots, $byCode, $all);
			if (in_array($part->type, self::BoardTypes, true)) {
				$list->addBoard($material?->id, $material->name ?? 'No material', $part, $item->name);
			} else {
				$list->addOther($material?->id, $material->name ?? 'No material', $part, $item->name);
			}
		}
	}

	/** @return array<int, array<mixed>> the scene's definition bodies by id */
	private static function definitions(SceneDto $scene): array
	{
		$definitions = [];
		foreach ($scene->definitions as $definition) {
			$definitions[$definition->id] = JsonDecoder::decodeObject(json_encode($definition->definition, JSON_THROW_ON_ERROR));
		}

		return $definitions;
	}

	/** @return array<mixed> */
	private static function params(ItemDto $item): array
	{
		return is_array($item->params) ? $item->params : [];
	}

	/**
	 * @param array<mixed> $params
	 * @param list<mixed> $slots
	 * @param array<string, MaterialDto> $byCode
	 * @param list<MaterialDto> $all
	 */
	private static function partMaterial(
		ResolvedPart $part,
		ItemDto $item,
		array $params,
		array $slots,
		array $byCode,
		array $all,
	): ?MaterialDto
	{
		$byId = static fn (?int $id): ?MaterialDto => array_find($all, static fn (MaterialDto $m): bool => $m->id === $id);
		$chosen = is_array($params['materials'] ?? null) ? ($params['materials'][$part->material ?? ''] ?? null) : null;
		if (is_int($chosen)) {
			return $byId($chosen);
		}

		$index = null;
		foreach ($slots as $i => $slot) {
			if (is_array($slot) && ($slot['key'] ?? null) === $part->material) {
				$index = $i;
			}
		}
		if (($index === null || $index === 0) && $item->materialId !== null) {
			return $byId($item->materialId);
		}
		$slot = $index === null ? null : $slots[$index];
		$default = is_array($slot) ? ($slot['default'] ?? null) : null;

		// A slot without a default takes the item material, like a part without a slot.
		return is_string($default) ? ($byCode[$default] ?? null) : $byId($item->materialId);
	}
}
