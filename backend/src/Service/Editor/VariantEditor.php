<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor;

use FlatDesigner\Dto\EditResultDto;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\User;
use FlatDesigner\Model\Entity\VariantSet;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Service\Editor\Element\ElementHandler;
use FlatDesigner\Service\Editor\Exception\EditException;

/**
 * Variant sets as batches (one revision, one undo step): making one of groups, adding a variant, switching
 * the shown variant and deciding the set. A variant is an item group pointing to the set; only the set's
 * active group is shown. Renaming goes through `SceneEditor::update()`. The caller has checked access.
 */
final readonly class VariantEditor
{
	private ElementHandler $groups;

	private ElementHandler $sets;

	public function __construct(
		ElementHandlers $handlers,
		private BatchWriter $writer,
		private GroupEditor $groupEditor,
		private ItemGroupRepository $groupRepository,
	) {
		$this->groups = $handlers->get(ElementTypeEnum::Group);
		$this->sets = $handlers->get(ElementTypeEnum::VariantSet);
	}

	/**
	 * A variant set of the given groups (alternatives of one piece of furniture); the first is shown, the
	 * others are hidden. Groups already in another set are refused.
	 *
	 * @param list<int> $groupIds
	 */
	public function createVariantSet(User $user, Floor $floor, string $name, array $groupIds, ?int $baseRevision = null): EditResultDto
	{
		return $this->writer->transaction(function () use ($user, $floor, $name, $groupIds, $baseRevision): EditResultDto {
			$groups = array_map(fn (int $id): ItemGroup => $this->groupEditor->group($floor, $id), array_values(array_unique($groupIds)));
			foreach ($groups as $group) {
				if ($group->variantSet !== null && $group->variantSet->deletedAt === null) {
					throw EditException::invalid(
						sprintf('Group "%s" is a variant of "%s" already.', $group->name, $group->variantSet->name),
					);
				}
			}
			$state = $this->sets->normalize($floor, ['name' => $name, 'activeGroupId' => null], null);

			$batch = $this->writer->start(
				$floor,
				$baseRevision,
				sprintf('Made %s of %d group(s)', $this->sets->label($state), count($groups)),
			);
			$set = $this->writer->create($user, $floor, $batch, $this->sets, $state);
			foreach ($groups as $group) {
				$this->writer->update($user, $floor, $batch, $this->groups, $group, ['variantSetId' => $set->id]);
			}
			if ($groups !== []) {
				$this->writer->update($user, $floor, $batch, $this->sets, $set, ['activeGroupId' => $groups[0]->id]);
			}

			return $batch->result($this->sets->state($set));
		});
	}

	/**
	 * A new variant of the set, shown from now on: a copy of the variant `$fromGroupId` (with its items, in
	 * place), or an empty one.
	 */
	public function addVariant(
		User $user,
		Floor $floor,
		int $setId,
		?int $fromGroupId = null,
		?string $name = null,
		?int $baseRevision = null,
	): EditResultDto {
		return $this->writer->transaction(function () use ($user, $floor, $setId, $fromGroupId, $name, $baseRevision): EditResultDto {
			$set = $this->variantSet($floor, $setId);
			$source = $fromGroupId === null ? null : $this->variant($floor, $set, $fromGroupId);
			$name ??= $source === null
				? sprintf(
					'Variant %d',
					count($this->groupRepository->findBySet($set->id)) + 1,
				)
				: $source->name . ' (copy)';
			$state = $this->groups->normalize($floor, ['name' => $name, 'variantSetId' => $set->id], null);

			$batch = $this->writer->start($floor, $baseRevision, sprintf('Added variant "%s" to "%s"', trim($name), $set->name));
			$variant = $source === null
				? $this->writer->create($user, $floor, $batch, $this->groups, $state)
				: $this->groupEditor->copyGroup($user, $floor, $batch, $source, $state, 0, 0);
			$this->writer->update($user, $floor, $batch, $this->sets, $set, ['activeGroupId' => $variant->id]);

			return $batch->result($this->groups->state($variant));
		});
	}

	/** Shows the variant `$groupId` of the set (null: none of them). */
	public function switchVariant(User $user, Floor $floor, int $setId, ?int $groupId, ?int $baseRevision = null): EditResultDto
	{
		return $this->writer->transaction(function () use ($user, $floor, $setId, $groupId, $baseRevision): EditResultDto {
			$set = $this->variantSet($floor, $setId);
			$group = $groupId === null ? null : $this->variant($floor, $set, $groupId);
			if ($set->activeGroupId === $groupId) {
				return new EditResultDto($floor->project->revision, null, $this->sets->state($set));
			}

			$batch = $this->writer->start(
				$floor,
				$baseRevision,
				sprintf('Switched "%s" to %s', $set->name, $group === null ? 'none' : sprintf('"%s"', $group->name)),
			);
			$this->writer->update($user, $floor, $batch, $this->sets, $set, ['activeGroupId' => $groupId]);

			return $batch->result($this->sets->state($set));
		});
	}

	/**
	 * Decides the set for its active variant: the other variants are deleted with their items, the active one
	 * stays as a plain group and the set goes.
	 */
	public function deleteVariantSet(User $user, Floor $floor, int $setId, ?int $baseRevision = null): EditResultDto
	{
		return $this->writer->transaction(function () use ($user, $floor, $setId, $baseRevision): EditResultDto {
			$set = $this->variantSet($floor, $setId);
			$variants = $this->groupRepository->findBySet($set->id);
			$active = array_find($variants, static fn (ItemGroup $group): bool => $group->id === $set->activeGroupId);

			$batch = $this->writer->start(
				$floor,
				$baseRevision,
				$active === null ? sprintf('Deleted variant set "%s"', $set->name) : sprintf(
					'Kept "%s" of "%s"',
					$active->name,
					$set->name,
				),
			);
			foreach ($variants as $variant) {
				if ($variant !== $active) {
					$this->groupEditor->deleteWithItems($user, $floor, $batch, $variant);
				}
			}
			if ($active !== null) {
				$this->writer->update($user, $floor, $batch, $this->sets, $set, ['activeGroupId' => null]);
				$this->writer->update($user, $floor, $batch, $this->groups, $active, ['variantSetId' => null]);
			}
			$this->writer->delete($user, $floor, $batch, $this->sets, $set);

			return $batch->result(null);
		});
	}

	private function variantSet(Floor $floor, int $id): VariantSet
	{
		$set = $this->writer->existing($floor, $this->sets, $id);
		assert($set instanceof VariantSet);

		return $set;
	}

	private function variant(Floor $floor, VariantSet $set, int $groupId): ItemGroup
	{
		$group = $this->groupEditor->group($floor, $groupId);
		if ($group->variantSet?->id !== $set->id) {
			throw EditException::invalid(sprintf('Group "%s" is not a variant of "%s".', $group->name, $set->name));
		}

		return $group;
	}
}
