<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\VariantSetDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\VariantSet;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\VariantSetRepository;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;

/**
 * Variant sets: a name and the active variant (one of the groups pointing to the set). Switching the variant
 * is an ordinary change of `activeGroupId`; adding and deciding variants are batches in `GroupEditor`.
 */
final readonly class VariantSetHandler implements ElementHandler
{
	public function __construct(private VariantSetRepository $variantSetRepository, private ItemGroupRepository $groupRepository)
	{
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::VariantSet;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['name', 'activeGroupId'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(Floor $floor, array $fields): array
	{
		return ['name' => 'Variants', 'activeGroupId' => null];
	}

	public function find(Floor $floor, int $id): ?VariantSet
	{
		return $this->variantSetRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		$set = self::cast($element);
		return $set->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$set = self::cast($element);
		$active = $set->activeGroupId === null ? null : $this->groupRepository->findOnFloor($set->floor->id, $set->activeGroupId);

		return State::of(VariantSetDto::fromEntity($set, $active));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$activeGroupId = $fields->nullableId('activeGroupId');
		if ($activeGroupId !== null) {
			$group = $this->groupRepository->findOnFloor($floor->id, $activeGroupId);
			$set = $current === null ? null : self::cast($current);
			if ($group === null || $group->deletedAt !== null || $set === null || $group->variantSet?->id !== $set->id) {
				throw EditException::invalid(sprintf('Group %d is not a variant of this set.', $activeGroupId));
			}
		}

		return ['name' => $fields->string('name', 255), 'activeGroupId' => $activeGroupId];
	}

	public function create(Floor $floor, array $state): VariantSet
	{
		$set = State::stamp(new VariantSet(floor: $floor, name: ''));
		$this->apply($set, $state);

		return $set;
	}

	public function update(AEntity $element, array $state): void
	{
		$set = self::cast($element);
		$set->updatedAt = new DateTimeImmutable();
		$this->apply($set, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$set = self::cast($element);
		$set->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$set->updatedAt = new DateTimeImmutable();
		$this->variantSetRepository->persist($set);
	}

	public function label(array $state): string
	{
		return sprintf('variant set "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(VariantSet $set, array $state): void
	{
		$fields = new FieldReader($state);
		$set->name = $fields->string('name', 255);
		$set->activeGroupId = $fields->nullableId('activeGroupId');
		$this->variantSetRepository->persist($set);
	}

	private static function cast(AEntity $element): VariantSet
	{
		assert($element instanceof VariantSet);

		return $element;
	}
}
