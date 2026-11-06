<?php

declare(strict_types=1);

namespace Izbik\Service\Furniture\CutList;

use Izbik\Dto\CutListDto;
use Izbik\Dto\ItemDto;
use Izbik\Dto\MaterialDto;
use Izbik\Dto\SceneDto;
use Izbik\Model\Entity\Enum\ItemKindEnum;
use Izbik\Model\Entity\Enum\LocaleEnum;
use Izbik\Model\Entity\Floor;
use Izbik\Model\Entity\Project;
use Izbik\Model\Repository\FloorRepository;
use Izbik\Service\Furniture\DefinitionEvaluator;
use Izbik\Service\Furniture\DefinitionException;
use Izbik\Service\Furniture\DefinitionTranslations;
use Izbik\Service\Furniture\ResolvedPart;
use Izbik\Service\Json\JsonDecoder;
use Izbik\Service\Provider\SceneProvider;
use Izbik\Service\Translator\ErrorTranslator;
use Izbik\Service\Translator\Translator;
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
		private Translator $translator,
		private ErrorTranslator $errorTranslator,
	) {
	}

	/** `$locale` names the parts (by the definitions' `translations`) and writes the notes in it. */
	public function forProject(Project $project, LocaleEnum $locale = LocaleEnum::En): CutListDto
	{
		$floors = $this->floorRepository->findByProject($project->id);

		return $this->forScenes(array_map(fn (Floor $floor): array => [$floor, $this->sceneProvider->getScene($floor)], $floors), $locale);
	}

	public function forFloor(Floor $floor, LocaleEnum $locale = LocaleEnum::En): CutListDto
	{
		return $this->forScenes([[$floor, $this->sceneProvider->getScene($floor)]], $locale);
	}

	/**
	 * The cut list of floors whose scenes are built already (the quantities read them too).
	 *
	 * @param list<array{Floor, SceneDto}> $scenes
	 */
	public function forScenes(array $scenes, LocaleEnum $locale = LocaleEnum::En): CutListDto
	{
		$list = new CutListAccumulator();
		foreach ($scenes as [$floor, $scene]) {
			$this->addFloor($list, $floor, $scene, $locale);
		}

		return $list->result();
	}

	private function addFloor(CutListAccumulator $list, Floor $floor, SceneDto $scene, LocaleEnum $locale): void
	{
		$hidden = array_flip($scene->hiddenItemIds());
		$definitions = self::definitions($scene);
		$materials = [];
		foreach ($scene->materials as $material) {
			$materials[$material->code] = $material;
		}

		foreach ($scene->items as $item) {
			if ($item->kind === ItemKindEnum::Custom && !isset($hidden[$item->id])) {
				$this->addItem(
					$list,
					$item,
					$definitions[$item->definitionId ?? 0] ?? null,
					$floor,
					$materials,
					$scene->materials,
					$locale,
				);
			}
		}
	}

	/**
	 * @param array<mixed>|null $definition
	 * @param array<string, MaterialDto> $byCode
	 * @param list<MaterialDto> $all
	 */
	private function addItem(
		CutListAccumulator $list,
		ItemDto $item,
		?array $definition,
		Floor $floor,
		array $byCode,
		array $all,
		LocaleEnum $locale,
	): void {
		if ($definition === null) {
			$list->skip($item->id, $item->name, $floor->name, $this->translator->translate('cutList.definitionGone', $locale));
			return;
		}

		$params = self::params($item);
		$values = is_array($params['values'] ?? null) ? $params['values'] : [];

		try {
			$parts = $this->evaluator->evaluate($definition, $item->width, $item->depth, $item->height, $values);
		} catch (DefinitionException $e) {
			$list->skip($item->id, $item->name, $floor->name, $this->errorTranslator->translate($e->getMessage(), $locale));
			return;
		}

		$slots = is_array($definition['materialSlots'] ?? null) ? array_values($definition['materialSlots']) : [];
		$noMaterial = $this->translator->translate('cutList.noMaterial', $locale);
		foreach ($parts as $part) {
			$part = self::named($part, DefinitionTranslations::partName($definition, $part->name, $locale));
			$label = fn (string $piece): string => $this->translator->translate('cutList.' . $piece, $locale);
			foreach (FramedFront::cut($part, $params, $slots, $label) as $piece) {
				$material = self::partMaterial($piece, $item, $params, $slots, $byCode, $all);
				// A front's glass pane has no slot: it is listed as glass.
				$pane = $piece->type === 'glass' && $piece->material === null;
				$name = $pane ? $label('glassMaterial') : $material->name ?? $noMaterial;
				$this->addPiece($list, $piece, $pane ? null : $material?->id, $name, $item->name);
			}
		}
	}

	private function addPiece(CutListAccumulator $list, ResolvedPart $piece, ?int $materialId, string $material, string $itemName): void
	{
		if (!in_array($piece->type, self::BoardTypes, true)) {
			$list->addOther($materialId, $material, $piece, $itemName);
			return;
		}
		foreach (FoldingFront::panels($piece) as $panel) {
			$list->addBoard($materialId, $material, $panel, $itemName);
		}
	}

	private static function named(ResolvedPart $part, string $name): ResolvedPart
	{
		return $name === $part->name
			? $part
			: new ResolvedPart(
				$name,
				$part->type,
				$part->index,
				$part->x,
				$part->y,
				$part->z,
				$part->w,
				$part->h,
				$part->d,
				$part->material,
				$part->props,
				$part->path,
			);
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
