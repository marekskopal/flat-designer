<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Service\Editor\Element\ElementHandler;

/**
 * Operations on item groups that change several elements as one change (one revision, one undo step, logged
 * as a `ChangeBatch`): grouping, moving, copying, ungrouping and deleting. Variant sets are in `VariantEditor`;
 * renaming goes through `SceneEditor::update()`. The caller has checked access to the floor's project.
 */
final readonly class GroupEditor
{
	/** mm along x by which a copy is shifted unless the caller places it. */
	public const int DuplicateShift = 100;

	private ElementHandler $items;

	private ElementHandler $groups;

	public function __construct(
		private ElementHandlers $handlers,
		private BatchWriter $writer,
		private ItemRepository $itemRepository,
		private ItemGroupRepository $groupRepository,
	) {
		$this->items = $handlers->get(ElementTypeEnum::Item);
		$this->groups = $handlers->get(ElementTypeEnum::Group);
	}

	/**
	 * A new group of the given items (taken out of their previous groups); no items make an empty group.
	 *
	 * @param list<int> $itemIds
	 */
	public function createGroup(User $user, Floor $floor, string $name, array $itemIds, ?int $baseRevision = null): EditResultDto
	{
		return $this->writer->transaction(function () use ($user, $floor, $name, $itemIds, $baseRevision): EditResultDto {
			$items = array_map(fn (int $id): Item => $this->item($floor, $id), array_values(array_unique($itemIds)));
			$state = $this->groups->normalize($floor, ['name' => $name], null);

			$batch = $this->writer->start(
				$floor,
				$baseRevision,
				sprintf('Grouped %d item(s) as %s', count($items), $this->groups->label($state)),
			);
			$group = $this->writer->create($user, $floor, $batch, $this->groups, $state);
			foreach ($items as $item) {
				$this->writer->update($user, $floor, $batch, $this->items, $item, ['groupId' => $group->id]);
			}

			return $batch->result($this->groups->state($group));
		});
	}

	/**
	 * Moves the group's items by (dx, dy) mm and turns them by `rotation` degrees counter-clockwise about
	 * the centre of their joint footprint.
	 */
	public function moveGroup(
		User $user,
		Floor $floor,
		int $groupId,
		int $dx,
		int $dy,
		int $rotation = 0,
		?int $baseRevision = null,
	): EditResultDto
	{
		return $this->writer->transaction(function () use ($user, $floor, $groupId, $dx, $dy, $rotation, $baseRevision): EditResultDto {
			$group = $this->group($floor, $groupId);
			$items = $this->itemRepository->findByGroup($group->id);
			$rotation %= 360;
			if ($items === [] || $dx === 0 && $dy === 0 && $rotation === 0) {
				return new EditResultDto($floor->project->revision, null, $this->groups->state($group));
			}

			[$cx, $cy] = self::footprintCentre($items);
			$angle = deg2rad($rotation);
			$cos = cos($angle);
			$sin = sin($angle);

			$verb = $dx === 0 && $dy === 0 ? 'Rotated' : 'Moved';
			$batch = $this->writer->start($floor, $baseRevision, sprintf('%s group "%s"', $verb, $group->name));
			foreach ($items as $item) {
				$x = $item->x - $cx;
				$y = $item->y - $cy;
				$this->writer->update($user, $floor, $batch, $this->items, $item, [
					'x' => (int) round($cx + $x * $cos - $y * $sin) + $dx,
					'y' => (int) round($cy + $x * $sin + $y * $cos) + $dy,
					'rotation' => $item->rotation + $rotation,
				]);
			}

			return $batch->result($this->groups->state($group));
		});
	}

	/** Copy of the group with copies of its items, shifted by (dx, dy) mm; a copy of a variant is a plain group. */
	public function duplicateGroup(
		User $user,
		Floor $floor,
		int $groupId,
		int $dx = self::DuplicateShift,
		int $dy = 0,
		?string $name = null,
		?int $baseRevision = null,
	): EditResultDto {
		return $this->writer->transaction(function () use ($user, $floor, $groupId, $dx, $dy, $name, $baseRevision): EditResultDto {
			$source = $this->group($floor, $groupId);
			$state = $this->groups->normalize($floor, ['name' => $name ?? $source->name], null);

			$batch = $this->writer->start($floor, $baseRevision, sprintf('Duplicated group "%s"', $source->name));
			$copy = $this->copyGroup($user, $floor, $batch, $source, $state, $dx, $dy);

			return $batch->result($this->groups->state($copy));
		});
	}

	/** Dissolves the group; its items stay where they are (a variant's items show from then on). */
	public function ungroup(User $user, Floor $floor, int $groupId, ?int $baseRevision = null): EditResultDto
	{
		return $this->writer->transaction(function () use ($user, $floor, $groupId, $baseRevision): EditResultDto {
			$group = $this->group($floor, $groupId);

			$batch = $this->writer->start($floor, $baseRevision, sprintf('Ungrouped "%s"', $group->name));
			$this->release($user, $floor, $batch, $group);
			foreach ($this->itemRepository->findByGroup($group->id) as $item) {
				$this->writer->update($user, $floor, $batch, $this->items, $item, ['groupId' => null]);
			}
			$this->writer->delete($user, $floor, $batch, $this->groups, $group);

			return $batch->result(null);
		});
	}

	/** Deletes the group with its items; for the active variant, another variant of its set becomes active. */
	public function deleteGroup(User $user, Floor $floor, int $groupId, ?int $baseRevision = null): EditResultDto
	{
		return $this->writer->transaction(function () use ($user, $floor, $groupId, $baseRevision): EditResultDto {
			$group = $this->group($floor, $groupId);

			$batch = $this->writer->start($floor, $baseRevision, sprintf('Deleted group "%s"', $group->name));
			$this->release($user, $floor, $batch, $group);
			$this->deleteWithItems($user, $floor, $batch, $group);

			return $batch->result(null);
		});
	}

	/**
	 * Within a batch: a copy of the group with copies of its items, shifted by (dx, dy) mm.
	 *
	 * @param array<string, mixed> $state the copy's group state, normalised
	 */
	public function copyGroup(User $user, Floor $floor, ChangeBatch $batch, ItemGroup $source, array $state, int $dx, int $dy): ItemGroup
	{
		$copy = $this->writer->create($user, $floor, $batch, $this->groups, $state);
		assert($copy instanceof ItemGroup);
		foreach ($this->itemRepository->findByGroup($source->id) as $item) {
			$fields = array_intersect_key($this->items->state($item), array_flip($this->items->fields()));
			$this->writer->create($user, $floor, $batch, $this->items, $this->items->normalize($floor, [
				'groupId' => $copy->id,
				'x' => $item->x + $dx,
				'y' => $item->y + $dy,
			] + $fields, null));
		}

		return $copy;
	}

	/** Within a batch: deletes the group with its items (items first: undo goes backwards, so the group is back before they need it). */
	public function deleteWithItems(User $user, Floor $floor, ChangeBatch $batch, ItemGroup $group): void
	{
		foreach ($this->itemRepository->findByGroup($group->id) as $item) {
			$this->writer->delete($user, $floor, $batch, $this->items, $item);
		}
		$this->writer->delete($user, $floor, $batch, $this->groups, $group);
	}

	/** Before the active variant goes, the next variant of its set (or none) becomes active. */
	private function release(User $user, Floor $floor, ChangeBatch $batch, ItemGroup $group): void
	{
		$set = $group->variantSet;
		if ($set === null || $set->deletedAt !== null || $set->activeGroupId !== $group->id) {
			return;
		}

		$next = array_find($this->groupRepository->findBySet($set->id), static fn (ItemGroup $other): bool => $other->id !== $group->id);
		$this->writer->update(
			$user,
			$floor,
			$batch,
			$this->handlers->get(ElementTypeEnum::VariantSet),
			$set,
			['activeGroupId' => $next?->id],
		);
	}

	private function item(Floor $floor, int $id): Item
	{
		$item = $this->writer->existing($floor, $this->items, $id);
		assert($item instanceof Item);

		return $item;
	}

	/** The group on the floor; 404 when it is not there or deleted. */
	public function group(Floor $floor, int $id): ItemGroup
	{
		$group = $this->writer->existing($floor, $this->groups, $id);
		assert($group instanceof ItemGroup);

		return $group;
	}

	/**
	 * Centre of the bounding box of the items' (rotated) footprints, in mm.
	 *
	 * @param non-empty-list<Item> $items
	 * @return array{float, float}
	 */
	private static function footprintCentre(array $items): array
	{
		$xs = [];
		$ys = [];
		foreach ($items as $item) {
			$angle = deg2rad($item->rotation);
			$halfX = (abs(cos($angle)) * $item->width + abs(sin($angle)) * $item->depth) / 2;
			$halfY = (abs(sin($angle)) * $item->width + abs(cos($angle)) * $item->depth) / 2;
			array_push($xs, $item->x - $halfX, $item->x + $halfX);
			array_push($ys, $item->y - $halfY, $item->y + $halfY);
		}

		return [(min($xs) + max($xs)) / 2, (min($ys) + max($ys)) / 2];
	}
}
