<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\GroupDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\VariantSet;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\VariantSetRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * Item groups: a name and, for a variant, its variant set. Membership is the items' `groupId`; moving,
 * duplicating or deleting a group with its items are batches in `GroupEditor`.
 */
final readonly class GroupHandler implements ElementHandler
{
	public function __construct(private ItemGroupRepository $groupRepository, private VariantSetRepository $variantSetRepository)
	{
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Group;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name', 'variantSetId'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(Floor $floor, array $fields): array
	{
		return ['name' => 'Group', 'variantSetId' => null];
	}

	public function find(Floor $floor, int $id): ?ItemGroup
	{
		return $this->groupRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		$group = self::cast($element);
		return $group->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$group = self::cast($element);
		return State::of(GroupDto::fromEntity($group));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$variantSetId = $fields->nullableId('variantSetId');
		$this->variantSet($floor, $variantSetId);
		$group = $current === null ? null : self::cast($current);
		$set = $group?->variantSet;
		if (
			$group !== null
			&& $set !== null
			&& $set->deletedAt === null
			&& $set->activeGroupId === $group->id
			&& $variantSetId !== $set->id
		) {
			throw EditException::invalid(
				sprintf('The active variant of "%s" cannot leave it; switch to another variant first.', $set->name),
			);
		}

		return ['name' => $fields->string('name', 255), 'variantSetId' => $variantSetId];
	}

	public function create(Floor $floor, array $state): ItemGroup
	{
		$group = State::stamp(new ItemGroup(floor: $floor, name: ''));
		$this->apply($group, $state);

		return $group;
	}

	public function update(AEntity $element, array $state): void
	{
		$group = self::cast($element);
		$group->updatedAt = new DateTimeImmutable();
		$this->apply($group, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$group = self::cast($element);
		$group->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$group->updatedAt = new DateTimeImmutable();
		$this->groupRepository->persist($group);
	}

	public function label(array $state): string
	{
		return sprintf('group "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(ItemGroup $group, array $state): void
	{
		$fields = new FieldReader($state);
		$group->name = $fields->string('name', 255);
		$group->variantSet = $this->variantSet($group->floor, $fields->nullableId('variantSetId'));
		$this->groupRepository->persist($group);
	}

	private function variantSet(Floor $floor, ?int $setId): ?VariantSet
	{
		if ($setId === null) {
			return null;
		}

		$set = $this->variantSetRepository->findOnFloor($floor->id, $setId);
		if ($set === null || $set->deletedAt !== null) {
			throw EditException::invalid(sprintf('Variant set %d is not on this floor.', $setId));
		}

		return $set;
	}

	private static function cast(AEntity $element): ItemGroup
	{
		assert($element instanceof ItemGroup);

		return $element;
	}
}
