<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\GroupDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * Item groups. The group itself has a name only; membership is the items' `groupId`, and moving,
 * duplicating or deleting a group with its items are batches in `SceneEditor`.
 */
final readonly class GroupHandler implements ElementHandler
{
	public function __construct(private ItemGroupRepository $groupRepository)
	{
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Group;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(array $fields): array
	{
		return ['name' => 'Group'];
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
		return ['name' => new FieldReader($state)->string('name', 255)];
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
		$group->name = new FieldReader($state)->string('name', 255);
		$this->groupRepository->persist($group);
	}

	private static function cast(AEntity $element): ItemGroup
	{
		assert($element instanceof ItemGroup);

		return $element;
	}
}
