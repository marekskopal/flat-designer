<?php

declare(strict_types=1);

namespace FlatDesigner\Service\Editor\Element;

use DateTimeImmutable;
use FlatDesigner\Dto\ItemDto;
use FlatDesigner\Model\Entity\AEntity;
use FlatDesigner\Model\Entity\Enum\ElementTypeEnum;
use FlatDesigner\Model\Entity\Enum\ItemKindEnum;
use FlatDesigner\Model\Entity\Floor;
use FlatDesigner\Model\Entity\FurnitureDefinition;
use FlatDesigner\Model\Entity\Item;
use FlatDesigner\Model\Entity\ItemGroup;
use FlatDesigner\Model\Entity\Room;
use FlatDesigner\Model\Repository\FurnitureDefinitionRepository;
use FlatDesigner\Model\Repository\ItemGroupRepository;
use FlatDesigner\Model\Repository\ItemRepository;
use FlatDesigner\Model\Repository\RoomRepository;
use FlatDesigner\Service\Editor\CabinetParamsValidator;
use FlatDesigner\Service\Editor\Catalog\ItemKindCatalog;
use FlatDesigner\Service\Editor\ChairParamsValidator;
use FlatDesigner\Service\Editor\CustomItemValidator;
use FlatDesigner\Service\Editor\Exception\EditException;
use FlatDesigner\Service\Editor\FieldReader;
use FlatDesigner\Service\Editor\WardrobeParamsValidator;
use FlatDesigner\Service\Furniture\FurnitureDefinitionProvider;
use FlatDesigner\Service\Json\JsonDecoder;
use const JSON_THROW_ON_ERROR;

final readonly class ItemHandler implements ElementHandler
{
	private const int MaxParamsBytes = 16384;

	/** What the parts of a Custom item come out of. */
	private const array CustomGeometry = ['kind', 'definitionId', 'width', 'depth', 'height', 'params'];

	public function __construct(
		private ItemRepository $itemRepository,
		private RoomRepository $roomRepository,
		private ItemGroupRepository $groupRepository,
		private MaterialResolver $materialResolver,
		private ItemKindCatalog $catalog,
		private FurnitureDefinitionRepository $definitionRepository,
		private CustomItemValidator $customValidator,
	) {
	}

	public function type(): ElementTypeEnum
	{
		return ElementTypeEnum::Item;
	}

	/** @return list<string> */
	public function fields(): array
	{
		return ['roomId', 'groupId', 'kind', 'definitionId', 'name', 'x', 'y', 'z', 'width', 'depth', 'height', 'rotation', 'materialId', 'params'];
	}

	/** @return list<string> */
	public function restorableFields(): array
	{
		return $this->fields();
	}

	/** @return array<string, mixed> */
	public function defaults(array $fields): array
	{
		$kind = is_string($fields['kind'] ?? null) ? ItemKindEnum::tryFrom($fields['kind']) : null;
		$size = $this->catalog->get($kind ?? ItemKindEnum::Box);
		$name = ($kind ?? ItemKindEnum::Box)->value;
		// A Custom item starts at its definition's size and with its name.
		$definition = $kind === ItemKindEnum::Custom && is_int($fields['definitionId'] ?? null)
			? $this->definitionRepository->findById($fields['definitionId'])
			: null;
		if ($definition !== null) {
			$name = $definition->name;
			$size = self::definitionSize($definition) + $size;
		}

		return [
			'roomId' => null,
			'groupId' => null,
			'kind' => ItemKindEnum::Box->value,
			'definitionId' => null,
			'name' => $name,
			'x' => 0,
			'y' => 0,
			'z' => $size['z'],
			'width' => $size['width'],
			'depth' => $size['depth'],
			'height' => $size['height'],
			'rotation' => 0,
			'materialId' => null,
			'params' => [],
		];
	}

	public function find(Floor $floor, int $id): ?Item
	{
		return $this->itemRepository->findOnFloor($floor->id, $id);
	}

	public function isDeleted(AEntity $element): bool
	{
		$item = self::cast($element);
		return $item->deletedAt !== null;
	}

	/** @return array<string, mixed> */
	public function state(AEntity $element): array
	{
		$item = self::cast($element);
		return State::of(ItemDto::fromEntity($item));
	}

	/** @return array<string, mixed> */
	public function normalize(Floor $floor, array $state, ?AEntity $current): array
	{
		$fields = new FieldReader($state);
		$roomId = $fields->nullableId('roomId');
		$this->room($floor, $roomId);
		$groupId = $fields->nullableId('groupId');
		$this->group($floor, $groupId);
		$materialId = $fields->nullableId('materialId');
		$this->materialResolver->resolve($materialId);

		$kind = $fields->enum('kind', ItemKindEnum::class);
		$definitionId = $fields->nullableId('definitionId');
		$definition = $this->definition($floor, $definitionId);
		$width = $fields->int('width', 1, State::MaxSize);
		$depth = $fields->int('depth', 1, State::MaxSize);
		$height = $fields->int('height', 1, State::MaxSize);
		$params = self::params($fields->raw('params'));
		CabinetParamsValidator::validate($kind, $params, $width, $height);
		WardrobeParamsValidator::validate($kind, $params, $height);
		ChairParamsValidator::validate($kind, $params, $width, $height);
		if ($kind === ItemKindEnum::Wardrobe && is_int($params['profileMaterialId'] ?? null)) {
			$this->materialResolver->resolve($params['profileMaterialId']);
		}

		$state = [
			'roomId' => $roomId,
			'groupId' => $groupId,
			'kind' => $kind->value,
			'definitionId' => $definitionId,
			'name' => $fields->string('name', 255),
			'x' => $fields->int('x', -State::CoordinateLimit, State::CoordinateLimit),
			'y' => $fields->int('y', -State::CoordinateLimit, State::CoordinateLimit),
			'z' => $fields->int('z', -State::MaxSize, State::MaxSize),
			'width' => $width,
			'depth' => $depth,
			'height' => $height,
			'rotation' => (($fields->int('rotation', -3600, 3600) % 360) + 360) % 360,
			'materialId' => $materialId,
			'params' => $params,
		];
		$this->customValidator->validate(
			$kind,
			$definition,
			$params,
			$width,
			$depth,
			$height,
			$current === null || !State::equals(
				array_intersect_key($this->state($current), array_flip(self::CustomGeometry)),
				array_intersect_key($state, array_flip(self::CustomGeometry)),
			),
		);

		return $state;
	}

	public function create(Floor $floor, array $state): Item
	{
		$item = State::stamp(new Item(
			floor: $floor,
			room: null,
			kind: ItemKindEnum::Box,
			name: '',
			x: 0,
			y: 0,
			z: 0,
			width: 0,
			depth: 0,
			height: 0,
			rotation: 0,
			material: null,
			params: null,
		));
		$this->apply($item, $state);

		return $item;
	}

	public function update(AEntity $element, array $state): void
	{
		$item = self::cast($element);
		$item->updatedAt = new DateTimeImmutable();
		$this->apply($item, $state);
	}

	public function setDeleted(AEntity $element, bool $deleted): void
	{
		$item = self::cast($element);
		$item->deletedAt = $deleted ? new DateTimeImmutable() : null;
		$item->updatedAt = new DateTimeImmutable();
		$this->itemRepository->persist($item);
	}

	public function label(array $state): string
	{
		return sprintf('item "%s"', is_string($state['name'] ?? null) ? $state['name'] : '?');
	}

	/** @param array<string, mixed> $state normalised */
	private function apply(Item $item, array $state): void
	{
		$fields = new FieldReader($state);
		$item->room = $this->room($item->floor, $fields->nullableId('roomId'));
		$item->group = $this->group($item->floor, $fields->nullableId('groupId'));
		$item->kind = $fields->enum('kind', ItemKindEnum::class);
		$definitionId = $fields->nullableId('definitionId');
		$item->definition = $definitionId === null ? null : $this->definitionRepository->findById($definitionId);
		$item->name = $fields->string('name', 255);
		$item->x = $fields->int('x', -State::CoordinateLimit, State::CoordinateLimit);
		$item->y = $fields->int('y', -State::CoordinateLimit, State::CoordinateLimit);
		$item->z = $fields->int('z', -State::MaxSize, State::MaxSize);
		$item->width = $fields->int('width', 1, State::MaxSize);
		$item->depth = $fields->int('depth', 1, State::MaxSize);
		$item->height = $fields->int('height', 1, State::MaxSize);
		$item->rotation = $fields->int('rotation', 0, 359);
		$item->material = $this->materialResolver->resolve($fields->nullableId('materialId'));
		$params = $fields->raw('params');
		$item->params = $params === [] || $params === null ? null : json_encode($params, JSON_THROW_ON_ERROR);
		$this->itemRepository->persist($item);
	}

	private function room(Floor $floor, ?int $roomId): ?Room
	{
		if ($roomId === null) {
			return null;
		}

		$room = $this->roomRepository->findOnFloor($floor->id, $roomId);
		if ($room === null || $room->deletedAt !== null) {
			throw EditException::invalid(sprintf('Room %d is not on this floor.', $roomId));
		}

		return $room;
	}

	/**
	 * A definition the floor's project may use: global, from the project owner's library or the project's own. A deleted
	 * one is still accepted (it is only left out of the lists), so undo and copies of items using it keep working — as
	 * long as the item still fits its definition, which copies are checked against like any new item.
	 */
	private function definition(Floor $floor, ?int $definitionId): ?FurnitureDefinition
	{
		if ($definitionId === null) {
			return null;
		}

		$definition = $this->definitionRepository->findById($definitionId);
		if ($definition === null || !FurnitureDefinitionProvider::isUsableIn($definition, $floor->project)) {
			throw EditException::invalid(sprintf(
				'Furniture definition %d is not available in this project (global, the project owner\'s or the project\'s own).',
				$definitionId,
			));
		}

		return $definition;
	}

	/** @return array{width?: int, depth?: int, height?: int} the definition's default size, as far as it has one */
	private static function definitionSize(FurnitureDefinition $definition): array
	{
		$size = JsonDecoder::decodeObject($definition->definition)['size'] ?? null;
		$result = [];
		foreach (['width', 'depth', 'height'] as $key) {
			if (is_array($size) && is_int($size[$key] ?? null)) {
				$result[$key] = $size[$key];
			}
		}

		return $result;
	}

	private function group(Floor $floor, ?int $groupId): ?ItemGroup
	{
		if ($groupId === null) {
			return null;
		}

		$group = $this->groupRepository->findOnFloor($floor->id, $groupId);
		if ($group === null || $group->deletedAt !== null) {
			throw EditException::invalid(sprintf('Group %d is not on this floor.', $groupId));
		}

		return $group;
	}

	/** @return array<string, mixed> */
	private static function params(mixed $params): array
	{
		if ($params === null || $params === []) {
			return [];
		}
		if (!is_array($params) || array_is_list($params)) {
			throw EditException::invalid('Field "params" must be a JSON object.');
		}
		if (strlen(json_encode($params, JSON_THROW_ON_ERROR)) > self::MaxParamsBytes) {
			throw EditException::invalid('Field "params" is too large.');
		}

		$object = [];
		foreach ($params as $key => $value) {
			$object[(string) $key] = $value;
		}

		return $object;
	}

	private static function cast(AEntity $element): Item
	{
		assert($element instanceof Item);

		return $element;
	}
}
